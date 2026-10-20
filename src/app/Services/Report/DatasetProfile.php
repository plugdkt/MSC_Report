<?php

namespace App\Services\Report;

use App\Models\Dataset;
use App\Models\DatasetColumn;
use App\Services\CodeLookup;
use Illuminate\Support\Collection;

/**
 * สรุปโครงสร้างข้อมูลแบบย่อสำหรับ AI และตัววางแผนแบบไม่ใช้ AI:
 * ส่งเฉพาะชื่อคอลัมน์ ชนิด สถิติ ค่าที่พบบ่อย และตัวอย่างไม่กี่แถวที่ปิดบังข้อมูลส่วนบุคคลแล้ว (spec FR-3.1, §6.5)
 */
class DatasetProfile
{
    private const TOP_VALUES = 8;

    public function __construct(private readonly CodeLookup $codes) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Dataset $dataset, bool $withSamples = true): array
    {
        $columns = $dataset->columns()->where('is_hidden', false)->get();

        return [
            'dataset' => array_filter([
                'name' => $dataset->name,
                'period' => $dataset->period_label,
                'rows' => $dataset->row_count,
                'title' => $dataset->title_lines ? implode(' / ', $dataset->title_lines) : null,
            ]),
            'columns' => $columns->map(fn (DatasetColumn $c) => $this->column($c))->values()->all(),
            'samples' => $withSamples ? $this->samples($dataset, $columns) : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function column(DatasetColumn $c): array
    {
        $p = $c->profile ?? [];
        $out = ['id' => $c->slug, 'name' => $c->display_name, 'type' => $c->type(), 'unique' => $p['unique'] ?? null, 'empty' => $p['nulls'] ?? 0];

        if ($c->isNumeric() && isset($p['sum'])) {
            $out += ['sum' => $p['sum'], 'min' => $p['min'], 'max' => $p['max']];
        }
        if ($c->type() === 'date' && isset($p['min'])) {
            $out += ['from' => $p['min'], 'to' => $p['max']];
        }
        if (isset($p['top'])) {
            $top = [];
            foreach (array_slice($p['top'], 0, self::TOP_VALUES, true) as $value => $count) {
                $value = (string) $value;
                $label = $c->code_set_id ? ($this->codes->label($c->code_set_id, $value)) : null;
                $shown = $c->is_pii ? PiiMasker::mask($value) : $value;
                $top[] = $label ? [$shown, $count, $label] : [$shown, $count];
            }
            $out['top'] = $top;
            if ($c->code_set_id && ($groups = $this->groups($c, $p['top'])) !== []) {
                $out['groups'] = $groups;
            }
        }
        if ($c->is_pii) {
            $out['pii'] = true;
        }

        return $out;
    }

    /**
     * @param  array<string, int>  $top
     * @return array<string, int>
     */
    private function groups(DatasetColumn $c, array $top): array
    {
        $groups = [];
        foreach ($top as $value => $count) {
            $g = $this->codes->group($c->code_set_id, (string) $value);
            if ($g !== null) {
                $groups[$g] = ($groups[$g] ?? 0) + $count;
            }
        }

        return $groups;
    }

    /**
     * @param  Collection<int, DatasetColumn>  $columns
     * @return list<list<mixed>>
     */
    private function samples(Dataset $dataset, $columns): array
    {
        $n = (int) config('ai.sample_rows');

        return $dataset->rows()->limit($n)->get()->map(function ($row) use ($columns) {
            return $columns->map(function (DatasetColumn $c) use ($row) {
                $v = $row->data[$c->slug] ?? null;
                if ($c->is_pii) {
                    return PiiMasker::mask($v);
                }

                return is_string($v) ? mb_strimwidth($v, 0, 60, '…') : $v;
            })->values()->all();
        })->all();
    }
}
