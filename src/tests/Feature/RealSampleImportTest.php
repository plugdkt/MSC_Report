<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\User;
use App\Services\Import\DatasetImporter;
use App\Services\Import\WorkbookInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ทดสอบกับไฟล์จริง ก.ย. 69 — ไฟล์และค่าคาดหวังอยู่นอก repo (SAMPLES_PATH) ถ้าไม่มีจะข้าม
 */
class RealSampleImportTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    /** @var array<string, mixed> */
    private array $expected;

    protected function setUp(): void
    {
        parent::setUp();

        $dir = rtrim((string) env('SAMPLES_PATH', base_path('../samples')), '/');
        $this->file = $dir.'/sep69.xlsx';
        if (! is_file($this->file) || ! is_file($dir.'/expected-sep69.json')) {
            $this->markTestSkipped('ไม่มีไฟล์ตัวอย่างจริงใน SAMPLES_PATH');
        }
        $this->expected = json_decode(file_get_contents($dir.'/expected-sep69.json'), true);
        Storage::fake('local');
    }

    public function test_inspection_matches_known_structure(): void
    {
        $inspection = app(WorkbookInspector::class)->inspect($this->file);
        $sheets = collect($inspection['sheets'])->keyBy('name');

        $this->assertSame('เต็ม', $inspection['recommended']);
        $this->assertSame([5, 103, 12, 1, 1], [$sheets['ก.ย']['header_start'], $sheets['ก.ย']['data_rows'], $sheets['ก.ย']['subtotal_rows'], $sheets['ก.ย']['total_rows'], $sheets['ก.ย']['check_rows']]);
        $this->assertSame([5, 7, 12], [$sheets['สรุป']['header_start'], $sheets['สรุป']['header_end'], $sheets['สรุป']['data_rows']]);
        $this->assertSame(2, $sheets['ITA']['repeat_rows']);
        $this->assertSame($this->expected['rows'], $sheets['เต็ม']['data_rows']);
    }

    public function test_both_data_sheets_give_the_expected_totals(): void
    {
        $path = Storage::disk('local')->putFileAs('uploads', $this->file, 'sep69.xlsx');

        foreach (['เต็ม' => 1, 'ก.ย' => 5] as $sheet => $header) {
            $dataset = Dataset::factory()->for(User::factory())->create(['stored_path' => $path]);
            app(DatasetImporter::class)->import($dataset, $sheet, $header, $header);
            $dataset->refresh();

            $sums = $dataset->columns->keyBy('source_name')->map(fn ($c) => $c->profile['sum'] ?? null);
            $this->assertSame($this->expected['rows'], $dataset->row_count, $sheet);
            $this->assertEquals($this->expected['base'], $sums['งบประมาณตั้งต้น'], $sheet);
            $this->assertEquals($this->expected['po'], $sums['มูลค่าใบสั่งซื้อ'], $sheet);
            $this->assertEquals($this->expected['used'], $sums['ยอดเงินจริง'], $sheet);
            $this->assertEquals($this->expected['remaining'], $sums['ยอดเงินที่เหลือ'], $sheet);
            $this->assertFalse(collect($dataset->quality)->contains('level', 'warn') && collect($dataset->quality)->contains(fn ($q) => str_contains($q['message'], 'ไม่ตรงกับแถวยอดรวม')), $sheet);
        }
    }
}
