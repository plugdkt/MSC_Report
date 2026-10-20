<?php

namespace Tests\Feature;

use App\Models\Dataset;
use App\Models\DatasetRow;
use App\Services\Report\AggregationEngine;
use App\Services\Report\SpecValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AggregationEngineTest extends TestCase
{
    use RefreshDatabase;

    private Dataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataset = Dataset::factory()->create(['status' => 'ready']);
        foreach ([['c1', 'วันที่', 'date'], ['c2', 'งาน', 'category'], ['c3', 'งบ', 'currency'], ['c4', 'ใช้จริง', 'currency'], ['c5', 'สถานะ', 'category']] as $i => [$slug, $name, $type]) {
            $this->dataset->columns()->create(['position' => $i + 1, 'slug' => $slug, 'source_name' => $name, 'display_name' => $name, 'inferred_type' => $type]);
        }
        $rows = [
            ['2025-10-05', 'การเงิน', 100, 80, 'เสร็จสิ้น'],
            ['2025-10-20', 'พัสดุ', 200, 50, 'ค้าง'],
            ['2025-12-03', 'การเงิน', 300, 300, 'เสร็จสิ้น'],
            ['2026-01-15', 'บริหาร', 50, 10, 'เสร็จสิ้น'],
            ['2026-01-20', 'วิจัย', 40, 0, null],
        ];
        foreach ($rows as $i => $r) {
            DatasetRow::create(['dataset_id' => $this->dataset->id, 'row_index' => $i + 1, 'source_row' => $i + 2, 'data' => array_combine(['c1', 'c2', 'c3', 'c4', 'c5'], $r)]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $widgets
     * @return array<string, array<string, mixed>>
     */
    private function compute(array $widgets, array $filters = []): array
    {
        [$spec, $errors] = app(SpecValidator::class)->validate(['title' => 't', 'filters' => $filters, 'widgets' => $widgets], $this->dataset->columns()->get(), 't');
        $this->assertSame([], $errors);

        return app(AggregationEngine::class)->compute($this->dataset, $spec);
    }

    public function test_kpis_ratio_and_percent_where(): void
    {
        $out = $this->compute([
            ['id' => 'a', 'type' => 'kpi', 'measure' => ['column' => 'c4', 'agg' => 'sum']],
            ['id' => 'b', 'type' => 'kpi', 'measure' => ['columns' => ['c3', 'c4'], 'agg' => 'sum']],
            ['id' => 'c', 'type' => 'gauge', 'measure' => ['columns' => ['c4'], 'agg' => 'ratio', 'denominator' => ['columns' => ['c3']]], 'target' => 70],
            ['id' => 'd', 'type' => 'kpi', 'measure' => ['column' => 'c5', 'agg' => 'percent_where', 'where' => ['op' => '=', 'value' => 'เสร็จสิ้น']]],
            ['id' => 'e', 'type' => 'kpi', 'measure' => ['column' => 'c4', 'agg' => 'median']],
            ['id' => 'f', 'type' => 'kpi', 'measure' => ['agg' => 'count']],
        ]);

        $this->assertSame(440.0, $out['a']['value']);
        $this->assertSame('บาท', $out['a']['unit']);
        $this->assertSame(1130.0, $out['b']['value']);
        $this->assertSame(63.77, $out['c']['value']);
        $this->assertSame('%', $out['c']['unit']);
        $this->assertSame(75.0, $out['d']['value']);
        $this->assertSame(50.0, $out['e']['value']);
        $this->assertSame(5.0, $out['f']['value']);
    }

    public function test_time_grains_use_thai_labels_and_fiscal_quarters(): void
    {
        $out = $this->compute([
            ['id' => 'm', 'type' => 'line', 'dimension' => ['column' => 'c1', 'time_grain' => 'month'], 'measure' => ['column' => 'c4', 'agg' => 'sum']],
            ['id' => 'q', 'type' => 'bar', 'dimension' => ['column' => 'c1', 'time_grain' => 'fiscal_quarter'], 'measure' => ['column' => 'c4', 'agg' => 'sum']],
            ['id' => 'y', 'type' => 'bar', 'dimension' => ['column' => 'c1', 'time_grain' => 'fiscal_year'], 'measure' => ['agg' => 'count']],
        ]);

        $this->assertSame(['ต.ค. 68', 'ธ.ค. 68', 'ม.ค. 69'], $out['m']['categories']);
        $this->assertSame([130.0, 300.0, 10.0], $out['m']['series'][0]['data']);
        $this->assertSame(['ไตรมาส 1/2569', 'ไตรมาส 2/2569'], $out['q']['categories']);
        $this->assertSame([430.0, 10.0], $out['q']['series'][0]['data']);
        $this->assertSame(['ปีงบ 2569'], $out['y']['categories']);
    }

    public function test_grouping_sorting_other_bucket_filters_and_table_total(): void
    {
        $out = $this->compute([
            ['id' => 'bar', 'type' => 'bar', 'dimension' => ['column' => 'c2'], 'measures' => [['column' => 'c3', 'agg' => 'sum'], ['column' => 'c4', 'agg' => 'sum']]],
            ['id' => 'pie', 'type' => 'pie', 'dimension' => ['column' => 'c2'], 'measure' => ['column' => 'c3', 'agg' => 'sum'], 'limit' => 3],
            ['id' => 'tbl', 'type' => 'table', 'dimension' => ['column' => 'c2'], 'measures' => [['column' => 'c4', 'agg' => 'sum']]],
            ['id' => 'flt', 'type' => 'kpi', 'measure' => ['column' => 'c4', 'agg' => 'sum'], 'filters' => [['column' => 'c2', 'op' => 'in', 'value' => ['พัสดุ', 'วิจัย']]]],
        ]);

        $this->assertSame(['การเงิน', 'พัสดุ', 'บริหาร', 'วิจัย'], $out['bar']['categories']);
        $this->assertSame([[400.0, 200.0, 50.0, 40.0], [380.0, 50.0, 10.0, 0.0]], array_column($out['bar']['series'], 'data'));
        $this->assertSame(['การเงิน', 'พัสดุ', 'อื่น ๆ'], $out['pie']['categories']);
        $this->assertSame([400.0, 200.0, 90.0], $out['pie']['series'][0]['data']);
        $this->assertSame(['รวม', 440.0], end($out['tbl']['rows']));
        $this->assertSame(50.0, $out['flt']['value']);
    }

    public function test_global_period_filter(): void
    {
        $out = $this->compute([['id' => 'a', 'type' => 'kpi', 'measure' => ['column' => 'c4', 'agg' => 'sum']]], [['column' => 'c1', 'op' => '>=', 'value' => '2026-01-01']]);

        $this->assertSame(10.0, $out['a']['value']);
    }

    public function test_validator_rejects_wrong_columns_and_aggregations(): void
    {
        [, $errors] = app(SpecValidator::class)->validate(['widgets' => [
            ['type' => 'kpi', 'measure' => ['column' => 'c2', 'agg' => 'sum']],
            ['type' => 'bar', 'dimension' => ['column' => 'c3'], 'measure' => ['agg' => 'count']],
            ['type' => 'donut'],
            ['type' => 'gauge', 'measure' => ['column' => 'c4', 'agg' => 'sum']],
            ['type' => 'kpi', 'measure' => ['column' => 'ไม่มี', 'agg' => 'sum']],
        ]], $this->dataset->columns()->get(), 't');

        $this->assertCount(5, $errors);
        $this->assertStringContainsString('ใช้กับคอลัมน์ตัวเลขเท่านั้น', $errors[0]);
        $this->assertStringContainsString('ไม่ใช่คอลัมน์ตัวเลข', $errors[1]);
        $this->assertStringContainsString('ไม่รองรับ', $errors[2]);
        $this->assertStringContainsString('gauge', $errors[3]);
        $this->assertStringContainsString('ไม่พบคอลัมน์', $errors[4]);
    }

    public function test_validator_accepts_display_names_instead_of_ids(): void
    {
        [$spec, $errors] = app(SpecValidator::class)->validate(['widgets' => [['type' => 'bar', 'dimension' => ['column' => 'งาน'], 'measure' => ['column' => 'ใช้จริง', 'agg' => 'sum']]]], $this->dataset->columns()->get(), 'ค่าเริ่มต้น');

        $this->assertSame([], $errors);
        $this->assertSame('ค่าเริ่มต้น', $spec['title']);
        $this->assertSame('c2', $spec['widgets'][0]['dimension']['column']);
        $this->assertSame(['c4'], $spec['widgets'][0]['measures'][0]['columns']);
    }
}
