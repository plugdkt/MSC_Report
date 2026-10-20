<?php

namespace App\Services\Report;

/**
 * วางแผน Dashboard จาก profile + คำตอบ โดยไม่ใช้ AI (spec §6.5 โหมดไม่ใช้ AI) — ใช้ spec รูปแบบเดียวกับ AI
 */
class RulePlanner
{
    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function plan(array $profile, array $answers): array
    {
        $cols = collect($profile['columns']);
        $executive = ($answers['audience'] ?? 'executive') !== 'supervisor';

        $chosen = array_values(array_filter((array) ($answers['measures'] ?? []), fn ($v) => $v !== RuleQuestions::AI_DECIDE));
        $numeric = $cols->filter(fn ($c) => in_array($c['type'], ['currency', 'number', 'integer'], true) && ($c['sum'] ?? 0) != 0);
        $measures = $chosen !== []
            ? collect($chosen)->map(fn ($id) => $numeric->firstWhere('id', $id))->filter()->values()
            : $numeric->sortByDesc(fn ($c) => ($c['type'] === 'currency' ? 1e15 : 0) + abs($c['sum']))->take(3)->values();

        $breakdown = $this->breakdown($cols, $answers['breakdown'] ?? RuleQuestions::AI_DECIDE);
        $date = $cols->firstWhere('type', 'date');
        $multiMonth = $date && isset($date['from']) && substr($date['from'], 0, 7) !== substr($date['to'], 0, 7);

        $widgets = [];
        $widgets[] = ['type' => 'kpi', 'title' => 'จำนวนรายการ', 'measure' => ['agg' => 'count']];
        foreach ($measures->take($executive ? 3 : 4) as $m) {
            $widgets[] = ['type' => 'kpi', 'title' => $m['name'].' รวม', 'measure' => ['columns' => [$m['id']], 'agg' => 'sum']];
        }

        $main = $measures->first();
        $mainMeasure = $main ? ['columns' => [$main['id']], 'agg' => 'sum'] : ['agg' => 'count'];
        $mainName = $main['name'] ?? 'จำนวนรายการ';

        if ($breakdown) {
            $widgets[] = ['type' => 'bar', 'title' => $mainName.' แยกตาม'.$breakdown['label'], 'dimension' => $breakdown['dimension'], 'measures' => $executive ? [$mainMeasure] : $this->allMeasures($measures, $mainMeasure), 'sort' => 'desc'];
            $widgets[] = ['type' => 'pie', 'title' => 'สัดส่วน'.$mainName.'ตาม'.$breakdown['label'], 'dimension' => $breakdown['dimension'], 'measure' => $mainMeasure];
        }

        if ($multiMonth && ($answers['period'] ?? 'all') !== 'last_month') {
            $grain = ($answers['period'] ?? null) === 'by_quarter' ? 'fiscal_quarter' : 'month';
            $widgets[] = ['type' => 'line', 'title' => $mainName.($grain === 'month' ? ' รายเดือน' : ' รายไตรมาส'), 'dimension' => ['column' => $date['id'], 'time_grain' => $grain], 'measures' => [$mainMeasure]];
        }

        if (! $executive && $breakdown) {
            $widgets[] = ['type' => 'table', 'title' => 'สรุปตาม'.$breakdown['label'], 'dimension' => $breakdown['dimension'], 'measures' => $this->allMeasures($measures, $mainMeasure)];
        }

        $filters = [];
        if ($multiMonth && ($answers['period'] ?? null) === 'last_month') {
            $filters[] = ['column' => $date['id'], 'op' => '>=', 'value' => substr($date['to'], 0, 7).'-01'];
        }

        $period = $profile['dataset']['period'] ?? null;

        return [
            'title' => 'สรุป'.($profile['dataset']['name'] ?? 'ข้อมูล'),
            'subtitle' => $period ? 'ข้อมูลงวด '.$period : null,
            'filters' => $filters,
            'widgets' => array_map(fn ($w, $i) => ['id' => 'w'.($i + 1)] + $w, $widgets, array_keys($widgets)),
        ];
    }

    /**
     * @return array{dimension: array<string, string>, label: string}|null
     */
    private function breakdown($cols, string $answer): ?array
    {
        if ($answer === 'none') {
            return null;
        }
        if ($answer !== RuleQuestions::AI_DECIDE) {
            [$id, $mode] = array_pad(explode(':', $answer, 2), 2, null);
            if ($c = $cols->firstWhere('id', $id)) {
                return ['dimension' => array_filter(['column' => $id, 'group_by' => $mode === 'group' ? 'group' : null]), 'label' => $c['name']];
            }
        }

        $grouped = $cols->first(fn ($c) => ! empty($c['groups']) && count($c['groups']) >= 2);
        if ($grouped) {
            return ['dimension' => ['column' => $grouped['id'], 'group_by' => 'group'], 'label' => $grouped['name']];
        }
        $cat = $cols->filter(fn ($c) => in_array($c['type'], ['category', 'code'], true) && ($c['unique'] ?? 0) >= 2 && ($c['unique'] ?? 0) <= 15)
            ->sortBy(fn ($c) => abs(($c['unique'] ?? 0) - 6))->first();

        return $cat ? ['dimension' => ['column' => $cat['id']], 'label' => $cat['name']] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allMeasures($measures, array $fallback): array
    {
        $list = $measures->map(fn ($m) => ['columns' => [$m['id']], 'agg' => 'sum'])->values()->all();

        return $list ?: [$fallback];
    }
}
