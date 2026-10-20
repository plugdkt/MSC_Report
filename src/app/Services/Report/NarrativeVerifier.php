<?php

namespace App\Services\Report;

/**
 * ตรวจว่าตัวเลขในบทสรุปมาจากผลคำนวณจริง (spec FR-4.4, §6.3)
 * ยอมรับการเขียนตัวเลขหลายแบบ: เต็ม (11,477,076.33), ล้านบาท (11.48 / 11.5 ล้าน), ร้อยละปัด (65.3% / 65%)
 */
class NarrativeVerifier
{
    private const THAI_MONTH = 'ม\.ค\.|ก\.พ\.|มี\.ค\.|เม\.ย\.|พ\.ค\.|มิ\.ย\.|ก\.ค\.|ส\.ค\.|ก\.ย\.|ต\.ค\.|พ\.ย\.|ธ\.ค\.|มกราคม|กุมภาพันธ์|มีนาคม|เมษายน|พฤษภาคม|มิถุนายน|กรกฎาคม|สิงหาคม|กันยายน|ตุลาคม|พฤศจิกายน|ธันวาคม';

    /**
     * ตัวเลขทั้งหมดที่ AI ใช้ได้ — ส่งให้ AI เป็นรายการ facts
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $computed
     * @return list<array{id:string, label:string, value:float, unit:?string}>
     */
    public function facts(array $spec, array $computed): array
    {
        $facts = [];
        $add = function (string $label, mixed $value, ?string $unit) use (&$facts) {
            if (is_numeric($value)) {
                $facts[] = ['id' => 'f'.(count($facts) + 1), 'label' => $label, 'value' => round((float) $value, 2), 'unit' => $unit];
            }
        };

        foreach ($spec['widgets'] as $w) {
            $d = $computed[$w['id']] ?? null;
            if (! $d) {
                continue;
            }
            if (in_array($w['type'], ['kpi', 'gauge'], true)) {
                $add($w['title'], $d['value'], $d['unit']);
                if (isset($d['target'])) {
                    $add($w['title'].' — เป้าหมาย', $d['target'], '%');
                    if ($d['value'] !== null) {
                        $add($w['title'].' — ส่วนต่างจากเป้าหมาย (จุด)', $d['value'] - $d['target'], 'จุด');
                    }
                }
            } elseif (isset($d['categories'])) {
                foreach (array_slice($d['series'], 0, 3) as $s) {
                    $total = array_sum(array_map('floatval', $s['data']));
                    foreach (array_slice($d['categories'], 0, 8, true) as $i => $cat) {
                        $add($w['title'].' — '.$cat.($s['name'] !== $w['title'] ? ' ('.$s['name'].')' : ''), $s['data'][$i], $s['unit']);
                        if ($s['unit'] !== '%' && $total != 0 && $s['data'][$i] !== null) {
                            $add($w['title'].' — '.$cat.' คิดเป็นร้อยละของทั้งหมด', $s['data'][$i] / $total * 100, '%');
                        }
                    }
                }
            } elseif (isset($d['rows'])) {
                foreach (array_slice($d['rows'], 0, 8) as $row) {
                    foreach (array_slice($row, 1) as $j => $cell) {
                        $add($w['title'].' — '.$row[0].' / '.($d['columns'][$j + 1]['label'] ?? ''), $cell, $d['columns'][$j + 1]['unit'] ?? null);
                    }
                }
            }
        }
        foreach ($computed['_infographic']['parts'] ?? [] as $p) {
            $add('สัดส่วน '.$p['label'], $p['exact_pct'], '%');
        }

        return $facts;
    }

    /**
     * @param  list<mixed>  $bullets
     * @param  list<array{id:string, label:string, value:float, unit:?string}>  $facts
     * @return list<array{text:string, verified:bool, unmatched:list<string>}>
     */
    public function verify(array $bullets, array $facts): array
    {
        $out = [];
        foreach (array_slice($bullets, 0, 6) as $b) {
            $text = trim(is_array($b) ? (string) ($b['text'] ?? '') : (string) $b);
            if ($text === '') {
                continue;
            }
            $unmatched = [];
            foreach ($this->numbers($text) as [$raw, $value, $scale]) {
                if (! $this->matches($value, $scale, $facts)) {
                    $unmatched[] = $raw;
                }
            }
            $out[] = ['text' => mb_substr($text, 0, 400), 'verified' => $unmatched === [], 'unmatched' => $unmatched];
        }

        return $out;
    }

    /**
     * @return list<array{0:string, 1:float, 2:string}> [ข้อความเดิม, ค่า, หน่วยที่เขียน (million|percent|plain)]
     */
    private function numbers(string $text): array
    {
        // ไม่นับปี พ.ศ./ค.ศ., วันที่ และลำดับไตรมาส
        $clean = preg_replace('/(\d{1,2})\s*(?:'.self::THAI_MONTH.')|ไตรมาส(?:ที่)?\s*\d|(?:พ\.ศ\.|ปีงบประมาณ|ปีงบ|ปี)\s*\d{4}|\b(?:25|20)\d{2}\b/u', ' ', $text) ?? $text;
        preg_match_all('/(?<![\d.])(-?\d[\d,]*(?:\.\d+)?)\s*(ล้าน|ลบ\.|%|ร้อยละ|เปอร์เซ็นต์)?/u', $clean, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $match) {
            $value = (float) str_replace(',', '', $match[1]);
            $suffix = $match[2] ?? '';
            $scale = in_array($suffix, ['ล้าน', 'ลบ.'], true) ? 'million' : (in_array($suffix, ['%', 'ร้อยละ', 'เปอร์เซ็นต์'], true) ? 'percent' : 'plain');
            if ($scale === 'plain' && abs($value) <= 12 && floor($value) == $value) {
                continue; // จำนวนเล็ก ๆ เช่น "4 หมวด", "อันดับ 1" — ไม่ใช่ผลคำนวณ
            }
            $out[] = [$match[0], $value, $scale];
        }

        return $out;
    }

    /**
     * @param  list<array{id:string, label:string, value:float, unit:?string}>  $facts
     */
    private function matches(float $value, string $scale, array $facts): bool
    {
        foreach ($facts as $f) {
            $v = $f['value'];
            $candidates = match ($scale) {
                'million' => [$v / 1e6],
                'percent' => $f['unit'] === '%' || $f['unit'] === 'จุด' ? [$v] : [],
                default => [$v, $v / 1e6, abs($v)],
            };
            foreach ($candidates as $c) {
                foreach ([0, 1, 2] as $decimals) {
                    if (abs(round($c, $decimals) - $value) < 0.0001 || abs(round(abs($c), $decimals) - abs($value)) < 0.0001) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public static function format(float|int|null $value, ?string $unit): string
    {
        if ($value === null) {
            return '—';
        }
        if ($unit === 'บาท' && abs($value) >= 1e6) {
            return number_format($value / 1e6, 2).' ล้านบาท';
        }
        if ($unit === '%') {
            return number_format($value, 1).'%';
        }
        $decimals = floor($value) == $value ? 0 : 2;

        return number_format($value, $decimals).($unit && $unit !== 'รายการ' ? ' '.$unit : ($unit === 'รายการ' ? ' รายการ' : ''));
    }
}
