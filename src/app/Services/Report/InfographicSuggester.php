<?php

namespace App\Services\Report;

use App\Models\Dataset;
use App\Models\DatasetColumn;
use Illuminate\Support\Collection;

/**
 * เสนอค่าตั้งอินโฟกราฟิกอัตโนมัติ (spec FR-6.5): หาคอลัมน์ ใช้จริง / ผูกพัน / คงเหลือ ตามแบบรายงานของผู้บริหาร
 * ถ้าไม่พบ ใช้คอลัมน์เงิน 2–3 คอลัมน์ที่ยอดสูงสุด
 */
class InfographicSuggester
{
    /** @var list<array{0:string, 1:string, 2:string}> [regex หัวคอลัมน์, ชื่อที่แสดง, สี] */
    private const BUDGET_PARTS = [
        ['/(ใช้จริง|ยอดเงินจริง|เบิกจ่ายจริง)/u', 'งบประมาณใช้จริง', 'green'],
        ['/(ใบสั่งซื้อ|ผูกพัน|\bPO\b)/iu', 'มูลค่าใบสั่งซื้อ', 'red'],
        ['/(คงเหลือ|ที่เหลือ)/u', 'งบประมาณคงเหลือ', 'blue'],
    ];

    /**
     * เลือกแบบอินโฟกราฟิกให้เหมาะกับรายงาน: ถ้า AI ออกแบบมาแล้วใช้ตามนั้น,
     * ถ้าข้อมูลเป็นงบประมาณ (ใช้จริง/ผูกพัน/คงเหลือ) ใช้แบบสัดส่วนตามที่ผู้บริหารใช้, นอกนั้นประกอบแบบเล่าเรื่องจาก widget
     *
     * @param  array<string, mixed>  $spec  spec ที่ผ่าน SpecValidator แล้ว
     * @return array<string, mixed>|null
     */
    public function forSpec(Dataset $dataset, array $spec, ?string $userUnit = null): ?array
    {
        if (! empty($spec['infographic']['enabled'])) {
            return $spec['infographic'];
        }
        $parts = $this->suggest($dataset, $userUnit);
        if ($parts !== null && $parts['total_label'] === 'งบประมาณทั้งหมด') {
            return $parts;
        }

        return $this->story($dataset, $spec, $userUnit);
    }

    /**
     * ประกอบอินโฟกราฟิกแบบเล่าเรื่องจาก widget ของรายงาน (ไม่ผูกกับงานด้านใดด้านหนึ่ง)
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function story(Dataset $dataset, array $spec, ?string $userUnit = null): array
    {
        $widgets = collect($spec['widgets'] ?? []);
        $first = fn (array $types) => $widgets->first(fn ($w) => in_array($w['type'], $types, true));

        $kpis = $widgets->whereIn('type', ['kpi'])->take(4)->pluck('id')->all();
        $ring = $first(['pie']) ?? $first(['gauge']);
        $bars = $first(['bar']);
        $trend = $first(['line']);
        $list = $first(['table']);

        $blocks = [];
        if ($kpis !== []) {
            $blocks[] = ['type' => 'kpis', 'refs' => $kpis];
        }
        if ($ring) {
            $blocks[] = ['type' => 'ring', 'ref' => $ring['id'], 'title' => $ring['title']];
        }
        if ($bars) {
            $blocks[] = ['type' => 'bars', 'ref' => $bars['id'], 'title' => $bars['title'], 'limit' => 8];
        }
        if ($trend) {
            $blocks[] = ['type' => 'trend', 'ref' => $trend['id'], 'title' => $trend['title']];
        }
        if ($list) {
            $blocks[] = ['type' => 'list', 'ref' => $list['id'], 'title' => $list['title'], 'limit' => 8];
        }
        $blocks[] = ['type' => 'highlights', 'title' => 'ประเด็นสำคัญสำหรับผู้บริหาร'];

        $charts = count(array_filter($blocks, fn ($b) => in_array($b['type'], ['ring', 'bars', 'trend', 'list'], true)));
        $size = $list || $charts >= 4 ? 'a4p' : ($charts >= 2 ? 'a4l' : 'wide');

        return [
            'mode' => 'story',
            'enabled' => true,
            'title' => $spec['title'] ?? 'สรุป'.$dataset->name,
            'subtitle' => trim(implode(' · ', array_filter([$dataset->period_label ? 'งวด '.$dataset->period_label : null, $userUnit ? 'หน่วยงาน '.$userUnit : null]))) ?: null,
            'size' => $size,
            'blocks' => $blocks,
            'image' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function suggest(Dataset $dataset, ?string $userUnit = null): ?array
    {
        /** @var Collection<int, DatasetColumn> $money */
        $money = $dataset->columns()->where('is_hidden', false)->get()
            ->filter(fn (DatasetColumn $c) => $c->type() === 'currency' && abs((float) ($c->profile['sum'] ?? 0)) > 0);

        $parts = [];
        foreach (self::BUDGET_PARTS as [$pattern, $label, $color]) {
            $col = $money->first(fn (DatasetColumn $c) => preg_match($pattern, $c->display_name) || preg_match($pattern, $c->source_name));
            if ($col && ! collect($parts)->contains(fn ($p) => $p['columns'] === [$col->slug])) {
                $parts[] = ['label' => $label, 'columns' => [$col->slug], 'color' => $color];
            }
        }
        $isBudget = count($parts) >= 2;

        if (! $isBudget) {
            $parts = $money->sortByDesc(fn ($c) => abs((float) $c->profile['sum']))->take(3)->values()
                ->map(fn (DatasetColumn $c, $i) => ['label' => $c->display_name, 'columns' => [$c->slug], 'color' => ['green', 'red', 'blue'][$i]])->all();
        }
        if (count($parts) < 2) {
            return null;
        }

        // ชีตข้อมูลดิบมักไม่มีหัวรายงาน — ใช้หัวรายงานจากชีตอื่นในไฟล์เดียวกัน (เช่น ชีตสรุป)
        $titles = collect($dataset->title_lines ?? [])
            ->merge(collect($dataset->inspection['sheets'] ?? [])->pluck('title_lines')->flatten())
            ->map(fn ($t) => trim(preg_replace('/\s+/u', ' ', (string) $t) ?? ''))->filter()->unique()->values();
        $title = $titles->first(fn ($t) => str_contains($t, 'รายงาน') && mb_strlen($t) <= 90);
        $unit = $titles->first(fn ($t) => str_starts_with($t, 'หน่วยงาน'));

        return [
            'enabled' => true,
            'title' => $title ? preg_replace('/พ\.ศ\s/u', 'พ.ศ. ', $title) : ($isBudget ? 'แบบรายงานผลการใช้จ่ายงบประมาณ' : 'สรุป'.$dataset->name),
            'period' => $dataset->period_label ? 'เดือน '.$dataset->period_label : null,
            'unit' => $unit ? preg_replace('/\s+มหาวิทยาลัย.*$/u', '', $unit) : ($userUnit ? 'หน่วยงาน '.$userUnit : null),
            'total_label' => $isBudget ? 'งบประมาณทั้งหมด' : 'รวมทั้งหมด',
            'parts' => $parts,
        ];
    }
}
