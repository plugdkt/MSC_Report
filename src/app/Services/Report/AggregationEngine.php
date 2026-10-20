<?php

namespace App\Services\Report;

use App\Models\Dataset;
use App\Models\DatasetColumn;
use App\Models\DatasetRow;
use App\Services\CodeLookup;
use Illuminate\Support\Collection;

/**
 * คำนวณตัวเลขของแต่ละ widget จากข้อมูลจริง — AI ไม่ได้คำนวณตัวเลขเอง (spec FR-4.2)
 */
class AggregationEngine
{
    private const MONTHS = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    private const OTHER = 'อื่น ๆ';

    /** @var list<array<string, mixed>> */
    private array $rows = [];

    /** @var Collection<string, DatasetColumn> */
    private Collection $columns;

    public function __construct(private readonly CodeLookup $codes) {}

    /**
     * @param  array<string, mixed>  $spec  spec ที่ผ่าน SpecValidator แล้ว
     * @return array<string, array<string, mixed>> widget id => ผลลัพธ์
     */
    public function compute(Dataset $dataset, array $spec): array
    {
        $this->columns = $dataset->columns()->get()->keyBy('slug');
        $this->rows = DatasetRow::where('dataset_id', $dataset->id)->orderBy('row_index')->pluck('data')->all();
        $base = $this->applyFilters($this->rows, $spec['filters'] ?? []);

        $out = [];
        foreach ($spec['widgets'] as $w) {
            $rows = $this->applyFilters($base, $w['filters'] ?? []);
            $out[$w['id']] = ['type' => $w['type'], 'title' => $w['title']] + match ($w['type']) {
                'kpi' => $this->kpi($rows, $w),
                'gauge' => $this->kpi($rows, $w) + ['target' => $w['target'] ?? null],
                'table' => $this->table($rows, $w),
                default => $this->chart($rows, $w),
            };
        }

        if (! empty($spec['infographic']['enabled'])) {
            $out['_infographic'] = $this->infographic($base, $spec['infographic']);
        }

        return $out;
    }

    /**
     * ยอดของแต่ละส่วนในอินโฟกราฟิก + ร้อยละจำนวนเต็มที่รวมกันได้ 100 พอดี (largest remainder)
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    private function infographic(array $rows, array $g): array
    {
        $values = array_map(fn ($p) => round(array_sum(array_map(fn ($r) => $this->rowSum($r, $p['columns']), $rows)), 2), $g['parts']);
        $total = round(array_sum($values), 2);
        $raw = array_map(fn ($v) => $total != 0 ? $v / $total * 100 : 0, $values);
        $pct = array_map(fn ($v) => (int) floor($v), $raw);
        $left = $total != 0 ? 100 - array_sum($pct) : 0;
        $order = array_keys($raw);
        usort($order, fn ($a, $b) => ($raw[$b] - floor($raw[$b])) <=> ($raw[$a] - floor($raw[$a])));
        foreach (array_slice($order, 0, max(0, $left)) as $i) {
            $pct[$i]++;
        }

        return [
            'parts' => array_map(fn ($p, $i) => ['label' => $p['label'], 'color' => $p['color'], 'value' => $values[$i], 'pct' => $pct[$i], 'exact_pct' => round($raw[$i], 2)], $g['parts'], array_keys($g['parts'])),
            'total' => $total,
        ];
    }

    public function measureLabel(array $m): string
    {
        if (isset($m['label'])) {
            return $m['label'];
        }
        $names = collect($m['columns'] ?? [])->map(fn ($s) => $this->columns->get($s)?->display_name ?? $s)->implode(' + ');

        return match ($m['agg']) {
            'count' => 'จำนวนรายการ',
            'count_distinct' => "จำนวน{$names} (ไม่ซ้ำ)",
            'avg' => "{$names} (เฉลี่ย)",
            'min' => "{$names} (ต่ำสุด)",
            'max' => "{$names} (สูงสุด)",
            'median' => "{$names} (มัธยฐาน)",
            'ratio' => "ร้อยละ {$names} ต่อ ".collect($m['denominator']['columns'])->map(fn ($s) => $this->columns->get($s)?->display_name ?? $s)->implode(' + '),
            'percent_where' => "ร้อยละที่ {$names} {$m['where']['op']} ".(is_array($m['where']['value']) ? implode(', ', $m['where']['value']) : $m['where']['value']),
            default => $names,
        };
    }

    public function measureUnit(array $m): ?string
    {
        if (in_array($m['agg'], ['ratio', 'percent_where'], true)) {
            return '%';
        }
        if (in_array($m['agg'], ['count', 'count_distinct'], true)) {
            return 'รายการ';
        }
        $types = collect($m['columns'] ?? [])->map(fn ($s) => $this->columns->get($s)?->type());

        return $types->contains('currency') ? 'บาท' : ($types->contains('percent') ? '%' : null);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $m
     */
    public function measure(array $rows, array $m): ?float
    {
        $cols = $m['columns'] ?? [];
        $first = $cols[0] ?? null;

        $value = match ($m['agg']) {
            'count' => $first ? count(array_filter($rows, fn ($r) => ($r[$first] ?? null) !== null)) : count($rows),
            'count_distinct' => count(array_unique(array_map(fn ($r) => (string) $r[$first], array_filter($rows, fn ($r) => ($r[$first] ?? null) !== null)))),
            'sum' => array_sum(array_map(fn ($r) => $this->rowSum($r, $cols), $rows)),
            'avg', 'min', 'max', 'median' => $this->stat($m['agg'], $this->rowValues($rows, $cols)),
            'ratio' => $this->ratio($rows, $cols, $m['denominator']['columns']),
            'percent_where' => $this->percentWhere($rows, $first, $m['where']),
            default => null,
        };

        return $value === null ? null : round((float) $value, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    private function kpi(array $rows, array $w): array
    {
        $m = $w['measure'];

        return ['value' => $this->measure($rows, $m), 'unit' => $this->measureUnit($m), 'label' => $this->measureLabel($m), 'rows' => count($rows)];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    private function chart(array $rows, array $w): array
    {
        $measures = $w['measures'] ?? [$w['measure']];
        $dim = $w['dimension'];
        $groups = $this->group($rows, $dim);
        $isTime = isset($dim['time_grain']);

        $primary = $measures[0];
        $totals = array_map(fn ($g) => $this->measure($g['rows'], $primary) ?? 0, $groups);
        $keys = array_keys($groups);
        if ($isTime) {
            sort($keys);
        } elseif (($w['sort'] ?? 'desc') !== 'none') {
            usort($keys, fn ($a, $b) => ($w['sort'] ?? 'desc') === 'asc' ? $totals[$a] <=> $totals[$b] : $totals[$b] <=> $totals[$a]);
        }

        $limit = $w['limit'] ?? ($w['type'] === 'pie' ? 8 : ($isTime ? 60 : 12));
        $additive = in_array($primary['agg'], ['sum', 'count'], true) && count($measures) === 1 && ! isset($w['series']);
        $other = [];
        if (count($keys) > $limit) {
            $rest = array_slice($keys, $limit - ($additive ? 1 : 0));
            $keys = array_slice($keys, 0, $limit - ($additive ? 1 : 0));
            if ($additive) {
                $other = array_merge(...array_map(fn ($k) => $groups[$k]['rows'], $rest));
            }
        }

        $categories = array_map(fn ($k) => $groups[$k]['label'], $keys);
        $series = [];

        if (isset($w['series'])) {
            $seriesCol = $w['series']['column'];
            $seriesTotals = [];
            foreach ($rows as $r) {
                $s = (string) ($r[$seriesCol] ?? '(ว่าง)');
                $seriesTotals[$s] = ($seriesTotals[$s] ?? 0) + $this->rowSum($r, $primary['columns'] ?? []) + (($primary['columns'] ?? []) === [] ? 1 : 0);
            }
            arsort($seriesTotals);
            foreach (array_slice(array_keys($seriesTotals), 0, 6) as $s) {
                $series[] = [
                    'name' => $this->displayValue($seriesCol, $s),
                    'unit' => $this->measureUnit($primary),
                    'data' => array_map(fn ($k) => $this->measure(array_values(array_filter($groups[$k]['rows'], fn ($r) => (string) ($r[$seriesCol] ?? '(ว่าง)') === $s)), $primary), $keys),
                ];
            }
        } else {
            foreach ($measures as $m) {
                $data = array_map(fn ($k) => $this->measure($groups[$k]['rows'], $m), $keys);
                if ($other !== []) {
                    $data[] = $this->measure($other, $m);
                }
                $series[] = ['name' => $this->measureLabel($m), 'unit' => $this->measureUnit($m), 'data' => $data];
            }
            if ($other !== []) {
                $categories[] = self::OTHER;
            }
        }

        return ['categories' => $categories, 'series' => $series, 'dimension_label' => $this->columns->get($dim['column'])?->display_name];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    private function table(array $rows, array $w): array
    {
        $measures = $w['measures'] ?? [];
        if (! isset($w['dimension'])) {
            return [
                'columns' => array_map(fn ($m) => ['label' => $this->measureLabel($m), 'unit' => $this->measureUnit($m)], $measures),
                'rows' => [array_map(fn ($m) => $this->measure($rows, $m), $measures)],
            ];
        }

        $chart = $this->chart($rows, $w + ['limit' => $w['limit'] ?? 20]);
        $tableRows = [];
        foreach ($chart['categories'] as $i => $label) {
            $tableRows[] = [$label, ...array_map(fn ($s) => $s['data'][$i], $chart['series'])];
        }
        if ($measures !== [] && collect($measures)->every(fn ($m) => in_array($m['agg'], ['sum', 'count'], true))) {
            $tableRows[] = ['รวม', ...array_map(fn ($m) => $this->measure($rows, $m), $measures)];
        }

        return [
            'columns' => [['label' => $chart['dimension_label'], 'unit' => null], ...array_map(fn ($s) => ['label' => $s['name'], 'unit' => $s['unit']], $chart['series'])],
            'rows' => $tableRows,
            'has_total' => $measures !== [] && end($tableRows)[0] === 'รวม',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, mixed>  $dim
     * @return array<string, array{label:string, rows:list<array<string, mixed>>}>
     */
    private function group(array $rows, array $dim): array
    {
        $groups = [];
        foreach ($rows as $r) {
            [$key, $label] = $this->dimensionKey($r[$dim['column']] ?? null, $dim);
            $groups[$key] ??= ['label' => $label, 'rows' => []];
            $groups[$key]['rows'][] = $r;
        }

        return $groups;
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function dimensionKey(mixed $value, array $dim): array
    {
        if ($value === null || $value === '') {
            return ['~', '(ว่าง)'];
        }
        if (isset($dim['time_grain']) && is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $d)) {
            [$y, $mo] = [(int) $d[1], (int) $d[2]];
            $fy = $mo >= 10 ? $y + 1 : $y;

            return match ($dim['time_grain']) {
                'day' => [$value, (int) $d[3].' '.self::MONTHS[$mo].' '.substr((string) ($y + 543), 2)],
                'quarter' => [sprintf('%04d-Q%d', $y, intdiv($mo - 1, 3) + 1), 'ไตรมาส '.(intdiv($mo - 1, 3) + 1).'/'.($y + 543)],
                'fiscal_quarter' => [sprintf('%04d-Q%d', $fy, intdiv(($mo + 2) % 12, 3) + 1), 'ไตรมาส '.(intdiv(($mo + 2) % 12, 3) + 1).'/'.($fy + 543)],
                'fiscal_year' => [(string) $fy, 'ปีงบ '.($fy + 543)],
                'year' => [(string) $y, (string) ($y + 543)],
                default => [sprintf('%04d-%02d', $y, $mo), self::MONTHS[$mo].' '.substr((string) ($y + 543), 2)],
            };
        }

        $text = (string) $value;
        $col = $this->columns->get($dim['column']);
        if (($dim['group_by'] ?? null) === 'group' && $col?->code_set_id) {
            $g = $this->codes->group($col->code_set_id, $text) ?? self::OTHER;

            return [$g, $g];
        }

        return [$text, $this->displayValue($dim['column'], $text)];
    }

    private function displayValue(string $slug, string $value): string
    {
        $col = $this->columns->get($slug);
        if ($col?->code_set_id && ($label = $this->codes->label($col->code_set_id, $value))) {
            return $label;
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{column:string, op:string, value:mixed}>  $filters
     * @return list<array<string, mixed>>
     */
    private function applyFilters(array $rows, array $filters): array
    {
        if ($filters === []) {
            return $rows;
        }

        return array_values(array_filter($rows, function ($r) use ($filters) {
            foreach ($filters as $f) {
                if (! $this->matches($r[$f['column']] ?? null, $f['op'], $f['value'])) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function matches(mixed $v, string $op, mixed $target): bool
    {
        if ($op === 'in') {
            return in_array((string) $v, array_map('strval', (array) $target), true);
        }
        if ($v === null) {
            return $op === '!=' && $target !== null;
        }
        $numeric = is_numeric($v) && is_numeric($target);
        $a = $numeric ? (float) $v : (string) $v;
        $b = $numeric ? (float) $target : (string) $target;

        return match ($op) {
            '=' => $a == $b,
            '!=' => $a != $b,
            '>' => $a > $b,
            '<' => $a < $b,
            '>=' => $a >= $b,
            '<=' => $a <= $b,
            default => false,
        };
    }

    /**
     * @param  list<string>  $cols
     */
    private function rowSum(array $row, array $cols): float
    {
        $sum = 0.0;
        foreach ($cols as $c) {
            $sum += is_numeric($row[$c] ?? null) ? (float) $row[$c] : 0.0;
        }

        return $sum;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $cols
     * @return list<float>
     */
    private function rowValues(array $rows, array $cols): array
    {
        $values = [];
        foreach ($rows as $r) {
            if (collect($cols)->contains(fn ($c) => is_numeric($r[$c] ?? null))) {
                $values[] = $this->rowSum($r, $cols);
            }
        }

        return $values;
    }

    /**
     * @param  list<float>  $values
     */
    private function stat(string $agg, array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);

        return match ($agg) {
            'avg' => array_sum($values) / $n,
            'min' => $values[0],
            'max' => $values[$n - 1],
            default => $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $num
     * @param  list<string>  $den
     */
    private function ratio(array $rows, array $num, array $den): ?float
    {
        $d = array_sum(array_map(fn ($r) => $this->rowSum($r, $den), $rows));

        return abs($d) < 0.0001 ? null : array_sum(array_map(fn ($r) => $this->rowSum($r, $num), $rows)) / $d * 100;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{op:string, value:mixed}  $where
     */
    private function percentWhere(array $rows, ?string $col, array $where): ?float
    {
        $base = array_filter($rows, fn ($r) => ($r[$col] ?? null) !== null);
        if ($base === []) {
            return null;
        }

        return count(array_filter($base, fn ($r) => $this->matches($r[$col], $where['op'], $where['value']))) / count($base) * 100;
    }
}
