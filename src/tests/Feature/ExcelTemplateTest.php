<?php

namespace Tests\Feature;

use App\Models\CodeSet;
use App\Models\Dataset;
use App\Models\User;
use App\Services\ExcelTemplate\TemplateWorkbookBuilder;
use Database\Seeders\CodeSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Support\BudgetWorkbook;
use Tests\TestCase;

class ExcelTemplateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CodeSetSeeder::class);
        $this->user = User::factory()->create();
    }

    private function downloadToFile(string $url): string
    {
        $response = $this->actingAs($this->user)->get($url)->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'dl').'.xlsx';
        copy($response->baseResponse->getFile()->getPathname(), $path);

        return $path;
    }

    private function upload(string $path, ?User $as = null): Dataset
    {
        $this->actingAs($as ?? $this->user)->post(route('datasets.store'), ['file' => new UploadedFile($path, 'filled.xlsx', null, null, true)])->assertRedirect();

        return Dataset::latest('id')->firstOrFail();
    }

    public function test_index_lists_standard_templates(): void
    {
        $this->actingAs($this->user)->get(route('excel-templates.index'))->assertOk()
            ->assertSee('รายงานผลการใช้จ่ายงบประมาณประจำเดือน')
            ->assertSee('บันทึกงานประจำวัน')
            ->assertSee('ติดตามโครงการ/กิจกรรม');
    }

    public function test_downloaded_template_has_marker_dropdowns_and_help_sheet(): void
    {
        CodeSet::where('key', 'fund')->first()->values()->createMany([['code' => '20200', 'label' => 'ก'], ['code' => '50500', 'label' => 'ข']]);

        $book = IOFactory::load($this->downloadToFile(route('excel-templates.download', 'budget_monthly')));

        $this->assertSame('budget_monthly', $book->getProperties()->getCustomPropertyValue(TemplateWorkbookBuilder::PROPERTY));
        $this->assertSame(['ข้อมูล', 'รายการตัวเลือก', 'คำอธิบาย'], $book->getSheetNames());
        $data = $book->getSheetByName('ข้อมูล');
        $this->assertSame('ปีงบประมาณ', $data->getCell('A1')->getValue());
        $this->assertSame('ยอดเงินที่เหลือ', $data->getCell('M1')->getValue());
        $this->assertSame('A2', $data->getFreezePane());

        $fundValidation = $data->getDataValidation('D2');
        $this->assertSame('list', $fundValidation->getType());
        $this->assertSame(['กองทุน', '20200', '50500'], array_column($book->getSheetByName('รายการตัวเลือก')->rangeToArray('A1:A3'), 0));
        $this->assertSame('hidden', $book->getSheetByName('รายการตัวเลือก')->getSheetState());
        $this->assertSame('decimal', $data->getDataValidation('G2')->getType());
    }

    public function test_filled_template_is_recognized_and_typed_from_definition(): void
    {
        $path = $this->downloadToFile(route('excel-templates.download', 'daily_work'));
        $book = IOFactory::load($path);
        $sheet = $book->getSheetByName('ข้อมูล');
        $rows = [
            ['15/03/2569', 'งานการเงิน', 'ตรวจเอกสารเบิกจ่าย', 'งานการเงิน', 'เสร็จสิ้น', 3, 2],
            ['16/03/2569', 'งานประชุม', 'จัดประชุมกรรมการ', 'งานบริหาร', 'กำลังดำเนินการ', 1, 1],
        ];
        foreach ($rows as $r => $row) {
            foreach ($row as $c => $v) {
                is_string($v)
                    ? $sheet->setCellValueExplicit([$c + 1, $r + 2], $v, DataType::TYPE_STRING)
                    : $sheet->setCellValue([$c + 1, $r + 2], $v);
            }
        }
        (new Xlsx($book))->save($path);

        $dataset = $this->upload($path);
        $this->assertSame(['key' => 'daily_work', 'name' => 'บันทึกงานประจำวัน'], $dataset->inspection['template']);
        $this->assertSame('ข้อมูล', $dataset->inspection['recommended']);

        $this->actingAs($this->user)->get(route('datasets.sheets', $dataset))->assertSee('ไฟล์นี้สร้างจากแบบฟอร์ม');
        $this->actingAs($this->user)->post(route('datasets.import', $dataset), ['sheet' => 'ข้อมูล', 'header_start' => 1, 'header_end' => 1]);

        $dataset->refresh();
        $this->assertSame(2, $dataset->row_count);
        $types = $dataset->columns->pluck('inferred_type', 'source_name');
        $this->assertSame('date', $types['วันที่รับงาน']);
        $this->assertSame('category', $types['สถานะ']);
        $this->assertSame('integer', $types['จำนวน (ชิ้นงาน)']);
        $this->assertSame('number', $types['ระยะเวลาดำเนินการ (วัน)']);
        $this->assertSame('ok', $dataset->quality[0]['level']);
        $this->assertStringContainsString('บันทึกงานประจำวัน', $dataset->quality[0]['message']);
    }

    public function test_template_from_dataset_keeps_column_names_for_next_period(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        BudgetWorkbook::create($path);
        $source = $this->upload($path);
        $this->actingAs($this->user)->post(route('datasets.import', $source), ['sheet' => 'ข้อมูล', 'header_start' => 1, 'header_end' => 1]);
        $source->columns()->where('source_name', 'กองทุน')->update(['display_name' => 'แหล่งเงิน']);

        $tplPath = $this->downloadToFile(route('datasets.excel-template', $source));
        $book = IOFactory::load($tplPath);
        $this->assertSame('dataset:'.$source->id, $book->getProperties()->getCustomPropertyValue(TemplateWorkbookBuilder::PROPERTY));
        $data = $book->getSheetByName('ข้อมูล');
        $this->assertSame('แหล่งเงิน', $data->getCell('D1')->getValue());
        $this->assertSame(['แหล่งเงิน', '20200', '50500'], array_column($book->getSheetByName('รายการตัวเลือก')->rangeToArray('D1:D3'), 0));

        $data->setCellValueExplicit('A2', '2570', DataType::TYPE_STRING);
        $data->setCellValueExplicit('D2', '20200', DataType::TYPE_STRING);
        $data->setCellValue('G2', 5000);
        (new Xlsx($book))->save($tplPath);

        $next = $this->upload($tplPath);
        $this->assertSame('dataset:'.$source->id, $next->inspection['template']['key']);
        $this->actingAs($this->user)->post(route('datasets.import', $next), ['sheet' => 'ข้อมูล', 'header_start' => 1, 'header_end' => 1]);
        $fund = $next->fresh()->columns->firstWhere('source_name', 'แหล่งเงิน');
        $this->assertSame('code', $fund->inferred_type);
        $this->assertSame(CodeSet::where('key', 'fund')->value('id'), $fund->code_set_id);

        $other = User::factory()->create();
        $this->actingAs($other)->get(route('datasets.excel-template', $source))->assertForbidden();
        $foreign = $this->upload($tplPath, $other);
        $this->assertArrayNotHasKey('template', $foreign->inspection);
    }

    public function test_unknown_template_key_is_ignored(): void
    {
        $book = new Spreadsheet;
        $book->getProperties()->setCustomProperty(TemplateWorkbookBuilder::PROPERTY, 'nope');
        $book->getActiveSheet()->setTitle('ข้อมูล')->fromArray([['ก', 'ข'], ['x', 1], ['y', 2], ['z', 3]]);
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        (new Xlsx($book))->save($path);

        $this->assertArrayNotHasKey('template', $this->upload($path)->inspection);
    }
}
