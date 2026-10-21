<?php

namespace App\Services\Report;

use App\Models\Dataset;
use App\Models\DatasetColumn;
use App\Models\DatasetRow;
use App\Services\CodeLookup;
use App\Support\Thai;
use Illuminate\Support\Collection;

/**
 * หา "ข้อสังเกต" จากข้อมูลจริงก่อนส่งให้ AI (ไม่ใช้ AI, ไม่ผูกกับงานด้านใด):
 * - โครงสร้างส่วนย่อย-ยอดรวม (เช่น งบ = ใช้จริง + ผูกพัน + คงเหลือ, รับ = เสร็จ + ค้าง) ตรวจจากทุกแถว
 * - สัดส่วน/อันดับตามกลุ่ม, ร้อยละของส่วนย่อยต่อยอดรวมตามกลุ่ม, รายการที่ยังเป็นศูนย์, การกระจุกตัว, แนวโน้มรายเดือน
 * แต่ละข้อมี widget ที่คำนวณได้จริงแนบไว้ — AI เลือกใช้ด้วย {"from":"i3"} แทนการเขียนสูตรเอง
 */
class InsightFinder
{
    private const MAX_INSIGHTS = 12;

    private const SAMPLE_ROWS = 5000;

    /** @var Collection<int, DatasetColumn> */
    private Collection $columns;

    public function __construct(
        private readonly SpecValidator $validator,
        private readonly AggregationEngine $engine,
        private readonly CodeLookup $codes,
    ) {}

    /**
     * @return array{structure: ?array{whole: list<string>, parts: list<string>, text: string}, insights: list<array{id: string, text: string, widget: array<string, mixed>}>}
     */
    public function find(Dataset $dataset): array
    {
        $this->columns = $dataset->columns()->where('is_hidden', false)->get();
        $rows = DatasetRow::where('dataset_id', $dataset->id)->orderBy('row_index')->limit(self::SAMPLE_ROWS)->pluck('data')->all();
        if ($rows === []) {
            return ['structure' => null, 'insights' => []];
        }

        $measures = $this->measures();
        $dims = $this->dimensions();
        $entity = $this->entity(count($rows));
        $structure = $this->structure($rows, $measures);

        $candidates = [];
        if ($structure) {
            $whole = ['agg' => 'sum', 'columns' => $structure['whole']];
            foreach ($structure['parts'] as $part) {
                $ratio = ['agg' => 'ratio', 'columns' => [$part], 'denominator' => ['columns' => $structure['whole']]];
                $candidates[] = ['kind' => 'overall_ratio', 'prio' => 1, 'part' => $part, 'widget' => ['type' => 'gauge', 'title' => 'ร้อยละ'.$this->name([$part]).'ต่อ'.$this->name($structure['whole']), 'measure' => $ratio]];
                foreach (array_slice($dims, 0, 2) as $k => $dim) {
                    $candidates[] = ['kind' => 'ratio_by', 'prio' => $k === 0 ? 2 : 5, 'part' => $part, 'dim' => $dim, 'widget' => ['type' => 'bar', 'title' => 'ร้อยละ'.$this->name([$part]).' แยกตาม'.$this->dimName($dim), 'dimension' => $dim, 'measures' => [$ratio], 'sort' => 'asc']];
                }
                if ($entity) {
                    $candidates[] = ['kind' => 'zero_part', 'prio' => 3, 'part' => $part, 'widget' => [
                        'type' => 'table', 'title' => $this->dimName($entity).'ที่ยังไม่มี'.$this->name([$part]),
                        'dimension' => $entity, 'measures' => [$whole, ['agg' => 'sum', 'columns' => [$part]]],
                        'filters' => [['column' => $part, 'op' => '=', 'value' => 0], ['column' => $structure['whole'][0], 'op' => '>', 'value' => 0]],
                        'sort' => 'desc', 'limit' => 20,
                    ]];
                }
            }
        }
        foreach (array_slice($measures, 0, $structure ? 1 : 3) as $m) {
            $sum = ['agg' => 'sum', 'columns' => [$m->slug]];
            foreach (array_slice($dims, 0, 2) as $k => $dim) {
                $candidates[] = ['kind' => 'share_by', 'prio' => $k === 0 ? 2 : 4, 'dim' => $dim, 'widget' => ['type' => 'bar', 'title' => $m->display_name.' แยกตาม'.$this->dimName($dim), 'dimension' => $dim, 'measures' => [$sum], 'sort' => 'desc']];
            }
            if ($entity) {
                $candidates[] = ['kind' => 'top_entities', 'prio' => 4, 'widget' => ['type' => 'bar', 'title' => $this->dimName($entity).' ที่มี'.$m->display_name.'สูงสุด 10 อันดับ', 'dimension' => $entity, 'measures' => [$sum], 'sort' => 'desc', 'limit' => 10]];
            }
        }
        if (($date = $this->columns->first(fn ($c) => $c->type() === 'date' && ($c->profile['min'] ?? null) && substr((string) $c->profile['min'], 0, 7) !== substr((string) $c->profile['max'], 0, 7))) && $measures) {
            $candidates[] = ['kind' => 'trend', 'prio' => 2, 'widget' => ['type' => 'line', 'title' => $measures[0]->display_name.' รายเดือน', 'dimension' => ['column' => $date->slug, 'time_grain' => 'month'], 'measures' => [['agg' => 'sum', 'columns' => [$measures[0]->slug]]]]];
        }

        // เรียงตามความสำคัญ (คงลำดับเดิมในระดับเดียวกัน) แล้วค่อยตัดจำนวน
        $order = array_keys($candidates);
        usort($order, fn ($a, $b) => [$candidates[$a]['prio'], $a] <=> [$candidates[$b]['prio'], $b]);
        $candidates = array_map(fn ($i) => $candidates[$i], $order);

        return ['structure' => $structure, 'insights' => $this->describe($dataset, $candidates)];
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @return list<array{id: string, text: string, widget: array<string, mixed>}>
     */
    private function describe(Dataset $dataset, array $candidates): array
    {
        $widgets = [];
        foreach ($candidates as $i => $c) {
            $widgets[] = ['id' => 'i'.($i + 1)] + $c['widget'];
        }
        [$spec] = $this->validator->validate(['title' => 'insights', 'widgets' => $widgets], $dataset->columns()->get(), 'insights');
        $byId = collect($spec['widgets'] ?? [])->keyBy('id');
        $computed = $byId->isEmpty() ? [] : $this->engine->compute($dataset, ['widgets' => $byId->values()->all()]);

        $out = [];
        foreach ($candidates as $i => $c) {
            $id = 'i'.($i + 1);
            if (! isset($computed[$id], $byId[$id])) {
                continue;
            }
            $text = $this->text($c, $computed[$id]);
            if ($text === null) {
                continue;
            }
            $widget = array_diff_key($byId[$id], ['layout' => 1]);
            $out[] = ['id' => 'i'.(count($out) + 1), 'text' => $text, 'widget' => ['id' => null] + $widget];
            if (count($out) >= self::MAX_INSIGHTS) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $d
     */
    private function text(array $c, array $d): ?string
    {
        $title = $c['widget']['title'];
        $pairs = fn () => collect($d['categories'] ?? [])->zip($d['series'][0]['data'] ?? [])->filter(fn ($p) => $p[1] !== null)->values();

        switch ($c['kind']) {
            case 'overall_ratio':
                return $d['value'] === null ? null : $title.' ภาพรวม '.$this->pct($d['value']);
            case 'ratio_by':
                $p = $pairs()->sortBy(fn ($x) => $x[1])->values();
                if ($p->count() < 2) {
                    return null;
                }

                return $title.': ต่ำสุด '.$p->first()[0].' '.$this->pct($p->first()[1]).', สูงสุด '.$p->last()[0].' '.$this->pct($p->last()[1]).' ('.$p->count().' กลุ่ม)';
            case 'share_by':
                $p = $pairs();
                $total = $p->sum(fn ($x) => $x[1]);
                if ($p->count() < 2 || $total == 0) {
                    return null;
                }
                $top = $p->sortByDesc(fn ($x) => $x[1])->first();

                return $title.': มากที่สุด '.$top[0].' '.$this->num($top[1], $d['series'][0]['unit'] ?? null).' ('.$this->pct($top[1] / $total * 100).' ของทั้งหมด), '.$p->count().' กลุ่ม';
            case 'top_entities':
                $p = $pairs();
                if ($p->count() < 3) {
                    return null;
                }
                $names = $p->take(3)->map(fn ($x) => mb_strimwidth((string) $x[0], 0, 40, '…'))->implode(', ');

                return $title.': อันดับต้น ๆ '.$names;
            case 'zero_part':
                $count = count(array_filter($d['rows'] ?? [], fn ($r) => ($r[0] ?? null) !== 'รวม'));
                if ($count === 0) {
                    return null;
                }

                return $title.': '.$count.($count >= 20 ? '+' : '').' รายการ (มียอดรวมแต่'.$this->name([$c['part']]).'เป็น 0)';
            case 'trend':
                $p = $pairs();
                if ($p->count() < 2) {
                    return null;
                }
                [$prev, $last] = [$p[$p->count() - 2], $p->last()];
                $change = $prev[1] != 0 ? ($last[1] - $prev[1]) / abs($prev[1]) * 100 : null;

                return $title.': '.$last[0].' '.$this->num($last[1], $d['series'][0]['unit'] ?? null).($change !== null ? ' ('.($change >= 0 ? '+' : '').number_format($change, 1).'% จาก '.$prev[0].')' : '');
        }

        return null;
    }

    /**
     * หา "ยอดรวม = ผลรวมส่วนย่อย" ที่เป็นจริงเกือบทุกแถว (≥95%) — ยอดรวมเป็นคอลัมน์เดียวหรือผลรวม 2 คอลัมน์, ส่วนย่อย 2–3 คอลัมน์
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<DatasetColumn>  $measures
     * @return array{whole: list<string>, parts: list<string>, text: string}|null
     */
    private function structure(array $rows, array $measures): ?array
    {
        $ids = array_map(fn ($c) => $c->slug, array_slice($measures, 0, 7));
        if (count($ids) < 3) {
            return null;
        }
        $v = fn (array $r, array $cols) => array_sum(array_map(fn ($c) => is_numeric($r[$c] ?? null) ? (float) $r[$c] : 0.0, $cols));

        $wholes = array_map(fn ($a) => [$a], $ids);
        foreach ($ids as $i => $a) {
            foreach (array_slice($ids, $i + 1) as $b) {
                $wholes[] = [$a, $b];
            }
        }
        $best = null;
        foreach ($wholes as $whole) {
            $rest = array_values(array_diff($ids, $whole));
            foreach ($this->subsets($rest) as $parts) {
                $ok = 0;
                $nonZero = 0;
                foreach ($rows as $r) {
                    $w = $v($r, $whole);
                    $nonZero += $w != 0 ? 1 : 0;
                    $ok += abs($w - $v($r, $parts)) <= 0.01 + abs($w) * 0.001 ? 1 : 0;
                }
                if ($nonZero > 0 && $ok / count($rows) >= 0.95 && (! $best || count($parts) > count($best['parts']))) {
                    $best = ['whole' => $whole, 'parts' => $parts];
                }
            }
        }

        return $best ? $best + ['text' => $this->name($best['whole']).' = '.implode(' + ', array_map(fn ($p) => $this->name([$p]), $best['parts']))] : null;
    }

    /**
     * @param  list<string>  $items
     * @return list<list<string>>
     */
    private function subsets(array $items): array
    {
        $out = [];
        $n = count($items);
        for ($mask = 1; $mask < (1 << $n); $mask++) {
            $set = [];
            for ($i = 0; $i < $n; $i++) {
                if ($mask & (1 << $i)) {
                    $set[] = $items[$i];
                }
            }
            if (count($set) >= 2 && count($set) <= 3) {
                $out[] = $set;
            }
        }

        return $out;
    }

    /**
     * @return list<DatasetColumn>
     */
    private function measures(): array
    {
        return $this->columns
            ->filter(fn ($c) => $c->isNumeric() && ! $c->is_pii && abs((float) ($c->profile['sum'] ?? 0)) > 0 && ($c->profile['unique'] ?? 2) > 1)
            ->sortByDesc(fn ($c) => ($c->type() === 'currency' ? 1e18 : 0) + abs((float) $c->profile['sum']))
            ->values()->all();
    }

    /**
     * มิติสำหรับแยกกลุ่ม: หมวด/รหัสที่มี 2–40 ค่า; ถ้ารหัสกับคำอธิบายคู่กัน ใช้คำอธิบาย; รหัสที่มีกฎจัดกลุ่มใช้กลุ่ม
     *
     * @return list<array<string, string>>
     */
    private function dimensions(): array
    {
        $cols = $this->columns->filter(fn ($c) => in_array($c->type(), ['category', 'code'], true) && ! $c->is_pii)->values();
        $dims = [];
        foreach ($cols as $c) {
            $u = (int) ($c->profile['unique'] ?? 0);
            if ($u < 2 || $u > 40) {
                continue;
            }
            $twin = $this->columns->first(fn ($o) => $o->id !== $c->id && abs($o->position - $c->position) === 1 && $o->type() === 'category' && (int) ($o->profile['unique'] ?? 0) === $u);
            if ($c->type() === 'code' && $twin) {
                continue;
            }
            $grouped = $c->code_set_id && $this->codes->sets()->firstWhere('id', $c->code_set_id)?->groupRules->isNotEmpty();
            // ชื่อที่อ่านออกมาก่อน: หมวดข้อความ/รหัสที่มีกลุ่มหรือชื่อ ดีกว่ารหัสล้วน
            $rank = $grouped ? 0 : ($c->type() === 'category' ? 1 : ($c->code_set_id ? 2 : 3));
            $dims[] = ['u' => $u, 'rank' => $rank, 'dim' => $grouped ? ['column' => $c->slug, 'group_by' => 'group'] : ['column' => $c->slug]];
        }
        usort($dims, fn ($a, $b) => [$a['rank'], $a['u']] <=> [$b['rank'], $b['u']]);

        return array_column(array_slice($dims, 0, 3), 'dim');
    }

    /**
     * มิติระดับ "รายการ" (เช่น โครงการ/ผู้รับบริการ) — ค่าไม่ซ้ำมาก ใช้หาอันดับและรายการที่ยังเป็นศูนย์
     *
     * @return array<string, string>|null
     */
    private function entity(int $rows): ?array
    {
        $c = $this->columns
            ->filter(fn ($c) => in_array($c->type(), ['text', 'category', 'code'], true) && ! $c->is_pii)
            ->filter(fn ($c) => ($u = (int) ($c->profile['unique'] ?? 0)) > 40 && $u >= $rows * 0.3)
            ->sortBy(fn ($c) => $c->type() === 'code' ? 1 : 0)->first();

        return $c ? ['column' => $c->slug] : null;
    }

    /**
     * @param  list<string>  $slugs
     */
    private function name(array $slugs): string
    {
        $names = array_map(fn ($s) => $this->columns->firstWhere('slug', $s)?->display_name ?? $s, $slugs);

        return count($names) > 1 ? '('.implode(' + ', $names).')' : $names[0];
    }

    /**
     * @param  array<string, string>  $dim
     */
    private function dimName(array $dim): string
    {
        return $this->columns->firstWhere('slug', $dim['column'])?->display_name ?? $dim['column'];
    }

    private function pct(float $v): string
    {
        return number_format($v, 1).'%';
    }

    private function num(float $v, ?string $unit): string
    {
        $k = Thai::kpi($v, $unit);

        return trim($k['value'].' '.$k['unit']);
    }
}
