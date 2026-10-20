<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BudgetWorkbook;
use Tests\TestCase;

class DatasetImportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->user = User::factory()->create();
    }

    private function workbookUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        BudgetWorkbook::create($path);

        return new UploadedFile($path, 'รายงานเงินคงเหลือ.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function uploadDataset(): Dataset
    {
        $this->actingAs($this->user)->post(route('datasets.store'), ['file' => $this->workbookUpload()])->assertRedirect();

        return Dataset::firstOrFail();
    }

    private function importSheet(Dataset $dataset, string $sheet): Dataset
    {
        $info = collect($dataset->inspection['sheets'])->firstWhere('name', $sheet);
        $this->actingAs($this->user)->post(route('datasets.import', $dataset), [
            'sheet' => $sheet,
            'header_start' => $info['header_start'],
            'header_end' => $info['header_end'],
        ])->assertRedirect(route('datasets.show', $dataset));

        return $dataset->fresh();
    }

    public function test_guest_cannot_upload(): void
    {
        $this->post(route('datasets.store'), ['file' => $this->workbookUpload()])->assertRedirect(route('login'));
    }

    public function test_rejects_macro_and_non_spreadsheet_files(): void
    {
        $this->actingAs($this->user);
        $this->post(route('datasets.store'), ['file' => UploadedFile::fake()->create('x.xlsm', 10)])->assertSessionHasErrors('file');
        $this->post(route('datasets.store'), ['file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('file');
        $this->assertSame(0, Dataset::count());
    }

    public function test_upload_inspects_all_sheets_and_recommends_the_flat_table(): void
    {
        $dataset = $this->uploadDataset();

        $this->assertSame('inspected', $dataset->status);
        Storage::disk('local')->assertExists($dataset->stored_path);
        $sheets = collect($dataset->inspection['sheets'])->keyBy('name');

        $this->assertSame('ข้อมูล', $dataset->inspection['recommended']);

        $report = $sheets['รายงาน'];
        $this->assertSame(5, $report['header_start']);
        $this->assertSame(4, $report['data_rows']);
        $this->assertSame(2, $report['subtotal_rows']);
        $this->assertSame(1, $report['total_rows']);
        $this->assertSame(1, $report['check_rows']);
        $this->assertSame(['รายงานผลการใช้จ่ายงบประมาณ ประจำปีงบประมาณ พ.ศ. 2569', 'ประจำเดือน กันยายน 2569', 'หน่วยงาน ตัวอย่าง'], $report['title_lines']);

        $summary = $sheets['สรุป'];
        $this->assertSame([5, 7], [$summary['header_start'], $summary['header_end']]);
        $this->assertSame(['ประเภทรายจ่าย', 'จำนวนงบประมาณที่ ได้รับจัดสรร', 'ยอดเงินใช้จริง', 'ยอดเงินคงเหลือ'], $summary['columns']);
        $this->assertSame(2, $summary['data_rows']);
    }

    public function test_import_report_sheet_excludes_total_rows_and_normalizes_thai_values(): void
    {
        $dataset = $this->importSheet($this->uploadDataset(), 'รายงาน');

        $this->assertSame('ready', $dataset->status);
        $this->assertSame(4, $dataset->row_count);
        $this->assertSame('กันยายน 2569', $dataset->period_label);

        $cols = $dataset->columns->keyBy('source_name');
        $this->assertSame('code', $cols['รหัสงบประมาณ']->inferred_type);
        $this->assertSame('code', $cols['กองทุน']->inferred_type);
        $this->assertSame('date', $cols['ณ วันที่']->inferred_type);
        $this->assertSame('currency', $cols['งบประมาณ']->inferred_type);
        $this->assertSame(BudgetWorkbook::TOTAL_BUDGET, (float) $cols['งบประมาณ']->profile['sum']);
        $this->assertSame(BudgetWorkbook::TOTAL_USED, (float) $cols['ใช้จริง']->profile['sum']);
        $this->assertSame(BudgetWorkbook::TOTAL_REMAINING, (float) $cols['คงเหลือ']->profile['sum']);

        $first = $dataset->rows()->first()->data;
        $this->assertSame('110000', $first[$cols['รหัสงบประมาณ']->slug]);
        $this->assertSame('2026-09-30', $first[$cols['ณ วันที่']->slug]);
        $second = $dataset->rows()->skip(1)->first()->data;
        $this->assertEquals(12000, $second[$cols['ใช้จริง']->slug]);

        $kinds = collect($dataset->excluded_rows)->countBy('kind');
        $this->assertSame(['subtotal' => 2, 'total' => 1, 'check' => 1], $kinds->all());

        $levels = collect($dataset->quality)->groupBy('level');
        $this->assertStringContainsString('งบประมาณ', $levels['ok'][0]['message']);
        $this->assertTrue($levels['warn']->contains(fn ($q) => str_contains($q['message'], '"ใช้จริง"')),
            'ผลรวมคอลัมน์ "ใช้จริง" ในไฟล์ไม่นับเซลล์ที่เป็นข้อความ จึงควรถูกแจ้งว่าไม่ตรง');
    }

    public function test_other_staff_cannot_see_or_import_dataset_but_admin_can_view(): void
    {
        $dataset = $this->uploadDataset();
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('datasets.sheets', $dataset))->assertForbidden();
        $this->actingAs($other)->post(route('datasets.import', $dataset), ['sheet' => 'ข้อมูล', 'header_start' => 1, 'header_end' => 1])->assertForbidden();

        $dataset = $this->importSheet($dataset, 'ข้อมูล');
        $this->actingAs($other)->get(route('datasets.show', $dataset))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('datasets.show', $dataset))->assertOk()->assertSee('รายการ');
    }

    public function test_changing_column_type_reimports_and_keeps_display_names(): void
    {
        $dataset = $this->importSheet($this->uploadDataset(), 'ข้อมูล');
        $columns = $dataset->columns;
        $fund = $columns->firstWhere('source_name', 'กองทุน');

        $payload = [];
        foreach ($columns as $c) {
            $payload[$c->slug] = ['display_name' => $c->display_name, 'type' => $c->type(), 'is_pii' => 0, 'is_hidden' => 0];
        }
        $payload[$fund->slug] = ['display_name' => 'แหล่งเงิน', 'type' => 'category', 'is_pii' => 0, 'is_hidden' => 0];

        $this->actingAs($this->user)->put(route('datasets.columns.update', $dataset), ['columns' => $payload])->assertRedirect();

        $fund = $dataset->fresh()->columns->firstWhere('source_name', 'กองทุน');
        $this->assertSame('แหล่งเงิน', $fund->display_name);
        $this->assertSame('category', $fund->type());
        $this->assertSame('ready', $dataset->fresh()->status);
        $this->assertSame(['20200' => 3, '50500' => 1], $fund->profile['top']);
    }

    public function test_owner_can_delete_dataset_and_file(): void
    {
        $dataset = $this->uploadDataset();

        $this->actingAs($this->user)->delete(route('datasets.destroy', $dataset))->assertRedirect(route('home'));

        $this->assertModelMissing($dataset);
        Storage::disk('local')->assertMissing($dataset->stored_path);
    }

    public function test_pages_render(): void
    {
        $dataset = $this->uploadDataset();
        $this->actingAs($this->user)->get(route('datasets.create'))->assertOk()->assertSee('อัปโหลดไฟล์ Excel');
        $this->actingAs($this->user)->get(route('datasets.sheets', $dataset))->assertOk()->assertSee('แนะนำ');
        $this->actingAs($this->user)->get(route('datasets.sheets', [$dataset, 'sheet' => 'รายงาน', 'header_start' => 5, 'header_end' => 5]))->assertOk()->assertSee('ยอดรวมย่อย');

        $this->importSheet($dataset, 'รายงาน');
        $this->actingAs($this->user)->get(route('datasets.show', $dataset))->assertOk()->assertSee('ข้อสังเกตจากการตรวจข้อมูล');
        $this->actingAs($this->user)->get(route('home'))->assertOk()->assertSee('พร้อมใช้งาน');
    }
}
