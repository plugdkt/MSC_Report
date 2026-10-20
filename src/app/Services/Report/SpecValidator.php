<?php

namespace App\Services\Report;

use App\Models\DatasetColumn;
use App\Services\CodeLookup;
use Illuminate\Support\Collection;

/**
 * ตรวจและทำให้ Dashboard Spec เป็นรูปแบบมาตรฐานก่อนคำนวณ (spec §6.2, FR-4.3)
 * — ชื่อคอลัมน์ต้องมีจริง, aggregation ต้องเข้ากับชนิดคอลัมน์, ชนิดกราฟต้องรองรับ
 */
class SpecValidator
{
    public const WIDGET_TYPES = ['kpi', 'bar', 'line', 'pie', 'table', 'gauge'];

    public const AGGS = ['count', 'count_distinct', 'sum', 'avg', 'min', 'max', 'median', 'ratio', 'percent_where'];

    public const NUMERIC_AGGS = ['sum', 'avg', 'min', 'max', 'median', 'ratio'];

    public const TIME_GRAINS = ['day', 'month', 'quarter', 'fiscal_quarter', 'fiscal_year', 'year'];

    public const OPS = ['=', '!=', 'in', '>', '<', '>=', '<='];

    private const MAX_WIDGETS = 12;

    /** @var Collection<string, DatasetColumn> */
    private Collection $columns;

    /** @var list<string> */
    private array $errors = [];

    public function __construct(private readonly CodeLookup $codes) {}

    /**
     * @param  array<string, mixed>|null  $spec
     * @param  Collection<int, DatasetColumn>  $columns
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function validate(?array $spec, Collection $columns, string $defaultTitle): array
    {
        $this->columns = $columns->keyBy('slug');
        $this->errors = [];

        if (! is_array($spec)) {
            return [[], ['คำตอบไม่ใช่ JSON object']];
        }

        $out = [
            'version' => 1,
            'title' => is_string($spec['title'] ?? null) && trim($spec['title']) !== '' ? mb_substr(trim($spec['title']), 0, 200) : $defaultTitle,
            'subtitle' => is_string($spec['subtitle'] ?? null) ? mb_substr(trim($spec['subtitle']), 0, 300) : null,
            'filters' => $this->filters($spec['filters'] ?? [], 'filters'),
            'widgets' => [],
        ];

        $widgets = is_array($spec['widgets'] ?? null) ? array_slice(array_values($spec['widgets']), 0, self::MAX_WIDGETS) : [];
        if ($widgets === []) {
            $this->errors[] = 'ต้องมี widgets อย่างน้อย 1 ชิ้น';
        }

        $usedIds = [];
        foreach ($widgets as $i => $w) {
            $widget = $this->widget(is_array($w) ? $w : [], $i + 1);
            if ($widget === null) {
                continue;
            }
            while (in_array($widget['id'], $usedIds, true)) {
                $widget['id'] .= 'x';
            }
            $usedIds[] = $widget['id'];
            $out['widgets'][] = $widget;
        }

        return [$out, $this->errors];
    }

    /**
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>|null
     */
    private function widget(array $w, int $n): ?array
    {
        $type = $w['type'] ?? null;
        if (! in_array($type, self::WIDGET_TYPES, true)) {
            $this->errors[] = "widget ที่ {$n}: type \"".json_encode($type, JSON_UNESCAPED_UNICODE).'" ไม่รองรับ (ใช้ได้: '.implode(', ', self::WIDGET_TYPES).')';

            return null;
        }
        $at = "widget ที่ {$n} ({$type})";
        $out = [
            'id' => preg_replace('/[^a-z0-9_]/i', '', (string) ($w['id'] ?? '')) ?: 'w'.$n,
            'type' => $type,
            'title' => is_string($w['title'] ?? null) && trim($w['title']) !== '' ? mb_substr(trim($w['title']), 0, 150) : 'ส่วนที่ '.$n,
        ];

        $measures = [];
        if (isset($w['measures']) && is_array($w['measures'])) {
            foreach ($w['measures'] as $m) {
                if (($mm = $this->measure($m, $at)) !== null) {
                    $measures[] = $mm;
                }
            }
        } elseif (isset($w['measure'])) {
            if (($mm = $this->measure($w['measure'], $at)) !== null) {
                $measures[] = $mm;
            }
        } elseif ($type !== 'table') {
            $measures[] = ['agg' => 'count'];
        }

        if ($measures === [] && $type !== 'table') {
            return null;
        }
        if (in_array($type, ['kpi', 'gauge', 'pie'], true)) {
            $out['measure'] = $measures[0];
        } else {
            $out['measures'] = $measures;
        }

        if (in_array($type, ['bar', 'line', 'pie', 'table'], true)) {
            $errorsBefore = count($this->errors);
            $dimension = $this->dimension($w['dimension'] ?? null, $at);
            if ($dimension === null && $type !== 'table') {
                if (count($this->errors) === $errorsBefore) {
                    $this->errors[] = "{$at}: ต้องมี dimension ที่ถูกต้อง";
                }

                return null;
            }
            if ($dimension) {
                $out['dimension'] = $dimension;
            }
        }
        if ($type === 'gauge') {
            if (! in_array($out['measure']['agg'], ['ratio', 'percent_where'], true)) {
                $this->errors[] = "{$at}: gauge ต้องใช้ agg ratio หรือ percent_where";

                return null;
            }
            if (isset($w['target']) && is_numeric($w['target'])) {
                $out['target'] = (float) $w['target'];
            }
        }
        if (in_array($type, ['bar', 'line'], true) && isset($w['series']['column']) && ($col = $this->column($w['series']['column'])) && in_array($col->type(), ['category', 'code', 'boolean'], true)) {
            $out['series'] = ['column' => $col->slug];
        }

        $out['filters'] = $this->filters($w['filters'] ?? [], $at);
        if (in_array($w['sort'] ?? null, ['asc', 'desc', 'none'], true)) {
            $out['sort'] = $w['sort'];
        }
        if (isset($w['limit']) && is_numeric($w['limit'])) {
            $out['limit'] = max(1, min(50, (int) $w['limit']));
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function measure(mixed $m, string $at): ?array
    {
        if (! is_array($m)) {
            $this->errors[] = "{$at}: measure ต้องเป็น object";

            return null;
        }
        $agg = $m['agg'] ?? 'sum';
        if (! in_array($agg, self::AGGS, true)) {
            $this->errors[] = "{$at}: agg \"{$agg}\" ไม่รองรับ (ใช้ได้: ".implode(', ', self::AGGS).')';

            return null;
        }

        $out = ['agg' => $agg];
        if (is_string($m['label'] ?? null)) {
            $out['label'] = mb_substr($m['label'], 0, 100);
        }

        $refs = isset($m['columns']) && is_array($m['columns']) ? $m['columns'] : (isset($m['column']) && $m['column'] !== '*' ? [$m['column']] : []);
        $cols = [];
        foreach ($refs as $ref) {
            $col = $this->column($ref);
            if (! $col) {
                $this->errors[] = "{$at}: ไม่พบคอลัมน์ \"".(is_scalar($ref) ? $ref : '?').'"';

                return null;
            }
            if (in_array($agg, self::NUMERIC_AGGS, true) && ! $col->isNumeric()) {
                $this->errors[] = "{$at}: agg {$agg} ใช้กับคอลัมน์ตัวเลขเท่านั้น แต่ {$col->slug} ({$col->display_name}) เป็น ".$col->type();

                return null;
            }
            $cols[] = $col->slug;
        }

        if (in_array($agg, self::NUMERIC_AGGS, true) || in_array($agg, ['count_distinct', 'percent_where'], true)) {
            if ($cols === []) {
                $this->errors[] = "{$at}: agg {$agg} ต้องระบุ column";

                return null;
            }
        }
        if ($cols !== []) {
            $out['columns'] = $cols;
        }

        if ($agg === 'ratio') {
            $den = [];
            foreach ((array) ($m['denominator']['columns'] ?? (isset($m['denominator']['column']) ? [$m['denominator']['column']] : [])) as $ref) {
                $col = $this->column($ref);
                if (! $col || ! $col->isNumeric()) {
                    $this->errors[] = "{$at}: denominator ต้องเป็นคอลัมน์ตัวเลขที่มีอยู่จริง";

                    return null;
                }
                $den[] = $col->slug;
            }
            if ($den === []) {
                $this->errors[] = "{$at}: ratio ต้องมี denominator";

                return null;
            }
            $out['denominator'] = ['columns' => $den];
        }

        if ($agg === 'percent_where') {
            $where = $m['where'] ?? null;
            if (! is_array($where) || ! in_array($where['op'] ?? null, self::OPS, true) || ! array_key_exists('value', $where)) {
                $this->errors[] = "{$at}: percent_where ต้องมี where {op, value}";

                return null;
            }
            $out['where'] = ['op' => $where['op'], 'value' => $where['value']];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dimension(mixed $d, string $at): ?array
    {
        if (! is_array($d) || ! isset($d['column'])) {
            return null;
        }
        $col = $this->column($d['column']);
        if (! $col) {
            $this->errors[] = "{$at}: ไม่พบคอลัมน์ dimension \"".(is_scalar($d['column']) ? $d['column'] : '?').'"';

            return null;
        }
        $out = ['column' => $col->slug];

        if ($col->type() === 'date') {
            $out['time_grain'] = in_array($d['time_grain'] ?? null, self::TIME_GRAINS, true) ? $d['time_grain'] : 'month';
        } elseif ($col->isNumeric()) {
            $this->errors[] = "{$at}: dimension ต้องเป็นหมวดหมู่/รหัส/วันที่ ไม่ใช่คอลัมน์ตัวเลข ({$col->slug})";

            return null;
        }

        $groupBy = $d['group_by'] ?? null;
        if ($groupBy === 'group' && $col->code_set_id && $this->codes->sets()->firstWhere('id', $col->code_set_id)?->groupRules->isNotEmpty()) {
            $out['group_by'] = 'group';
        } elseif ($col->code_set_id && $groupBy !== 'value') {
            $out['group_by'] = 'label';
        }

        return $out;
    }

    /**
     * @return list<array{column:string, op:string, value:mixed}>
     */
    private function filters(mixed $filters, string $at): array
    {
        $out = [];
        foreach ((array) $filters as $f) {
            if (! is_array($f) || ! ($col = $this->column($f['column'] ?? null)) || ! in_array($f['op'] ?? '=', self::OPS, true) || ! array_key_exists('value', $f)) {
                $this->errors[] = "{$at}: filter ไม่ถูกต้อง (ต้องมี column ที่มีจริง, op, value)";

                continue;
            }
            $out[] = ['column' => $col->slug, 'op' => $f['op'] ?? '=', 'value' => $f['value']];
        }

        return $out;
    }

    private function column(mixed $ref): ?DatasetColumn
    {
        if (! is_string($ref) || $ref === '') {
            return null;
        }

        return $this->columns->get($ref) ?? $this->columns->first(fn (DatasetColumn $c) => $c->display_name === $ref || $c->source_name === $ref);
    }
}
