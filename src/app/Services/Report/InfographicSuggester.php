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
