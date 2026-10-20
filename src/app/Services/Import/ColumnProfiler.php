<?php

namespace App\Services\Import;

/**
 * อนุมานชนิดคอลัมน์ แปลงค่า และสรุปสถิติ (spec FR-2, FR-1.10)
 */
class ColumnProfiler
{
    private const CODE_HEADER = '/(รหัส|code|เลขที่|^ปี|year|กองทุน|^id$|ลำดับ|^no\.?$|ประเภทเงิน|^เงิน$)/iu';

    private const MONEY_HEADER = '/(บาท|เงิน|งบ|ยอด|มูลค่า|ค่าใช้จ่าย|ราคา|รายได้|รายจ่าย|ใบสั่งซื้อ|กันเงิน)/u';

    private const PERCENT_HEADER = '/(%|ร้อยละ|เปอร์เซ็นต์|percent)/iu';

    private const PII_HEADER = '/(ชื่อ[\s\-–]*สกุล|ชื่อผู้|^ชื่อ$|นามสกุล|เลขบัตร|บัตรประชาชน|เลขประจำตัวประชาชน|เบอร์โทร|โทรศัพท์|มือถือ|e-?mail|อีเมล)/iu';

    private const BOOLEAN_VALUES = ['ใช่', 'ไม่ใช่', 'มี', 'ไม่มี', 'yes', 'no', 'true', 'false', 'y', 'n', '✓', '✔'];

    /**
     * @param  list<Cell>  $cells  ค่าในคอลัมน์ (เฉพาะแถวข้อมูล)
     */
    public function inferType(string $header, array $cells): string
    {
        $values = array_values(array_filter($cells, fn (Cell $c) => ! $c->isEmpty()));
        $n = count($values);
        if ($n === 0) {
            return 'text';
        }

        $dates = count(array_filter($values, fn (Cell $c) => $c->isDate || ($c->isText && ValueNormalizer::parseDate($c->text()) !== null)));
        if ($dates / $n >= 0.9) {
            return 'date';
        }

        $numbers = array_filter($values, fn (Cell $c) => $c->isNumber());
        $numericTexts = array_filter($values, fn (Cell $c) => $c->isText && preg_match('/^\d+$/', $c->text()));

        if (count($numericTexts) / $n >= 0.9) {
            return 'code'; // ตัวเลขที่เก็บเป็นข้อความ เช่น รหัส 20200
        }

        $parsedTexts = array_filter($values, fn (Cell $c) => $c->isText && ValueNormalizer::parseNumber($c->text()) !== null);
        if ((count($numbers) + count($parsedTexts)) / $n >= 0.9) {
            $nums = array_map(fn (Cell $c) => (float) ValueNormalizer::parseNumber($c->value), [...$numbers, ...$parsedTexts]);
            $allInt = ! array_filter($nums, fn (float $v) => floor($v) != $v);

            if (preg_match(self::PERCENT_HEADER, $header)) {
                return 'percent';
            }
            if ($allInt && preg_match(self::CODE_HEADER, $header) && ! preg_match(self::MONEY_HEADER, $header)) {
                return 'code';
            }
            if (preg_match(self::MONEY_HEADER, $header) || ! $allInt) {
                return 'currency';
            }

            return 'integer';
        }

        $texts = array_map(fn (Cell $c) => mb_strtolower($c->text()), $values);
        if (! array_diff(array_unique($texts), self::BOOLEAN_VALUES)) {
            return 'boolean';
        }

        $unique = count(array_unique($texts));
        if ($unique <= max(12, (int) ($n * 0.5))) {
            return 'category';
        }

        return 'text';
    }

    /**
     * แปลงค่าเซลล์ตามชนิดคอลัมน์ เพื่อเก็บลงฐานข้อมูล
     */
    public function convert(Cell $cell, string $type): string|float|bool|null
    {
        if ($cell->isEmpty()) {
            return null;
        }

        return match ($type) {
            'currency', 'number', 'percent' => ($n = ValueNormalizer::parseNumber($cell->value)) === null ? null : round($n, 2),
            'integer' => ($n = ValueNormalizer::parseNumber($cell->value)) === null ? null : (float) round($n),
            'date' => $cell->isDate ? (string) $cell->value : ValueNormalizer::parseDate($cell->text()),
            'boolean' => in_array(mb_strtolower($cell->text()), ['ใช่', 'มี', 'yes', 'true', 'y', '✓', '✔'], true),
            'code' => is_float($cell->value) && floor($cell->value) == $cell->value ? (string) (int) $cell->value : $cell->text(),
            default => $cell->text(),
        };
    }

    public function looksLikePii(string $header, array $values): bool
    {
        if (preg_match(self::PII_HEADER, $header)) {
            return true;
        }
        $strings = array_values(array_filter(array_map(fn ($v) => is_string($v) ? $v : null, $values)));
        if ($strings === []) {
            return false;
        }
        $hits = count(array_filter($strings, fn (string $s) => preg_match('/^\d[\d\-\s]{11,16}\d$/', $s) && strlen(preg_replace('/\D/', '', $s)) === 13
            || preg_match('/^[\w.+-]+@[\w-]+\.[\w.]+$/', $s)
            || preg_match('/^0\d{1,2}[\-\s]?\d{3}[\-\s]?\d{3,4}$/', $s)));

        return $hits / count($strings) >= 0.3;
    }

    /**
     * @param  list<string|float|bool|null>  $values  ค่าที่แปลงแล้ว
     * @return array<string, mixed>
     */
    public function profile(string $type, array $values): array
    {
        $nonNull = array_values(array_filter($values, fn ($v) => $v !== null && $v !== ''));
        $profile = [
            'count' => count($values),
            'nulls' => count($values) - count($nonNull),
            'unique' => count(array_unique(array_map(fn ($v) => is_bool($v) ? (int) $v : (string) $v, $nonNull))),
            'samples' => array_slice(array_values(array_unique(array_map(fn ($v) => is_bool($v) ? ($v ? 'ใช่' : 'ไม่ใช่') : (string) $v, $nonNull))), 0, 5),
        ];

        if (in_array($type, ['currency', 'number', 'integer', 'percent'], true) && $nonNull !== []) {
            $nums = array_map('floatval', $nonNull);
            sort($nums);
            $count = count($nums);
            $profile += [
                'min' => round($nums[0], 2),
                'max' => round($nums[$count - 1], 2),
                'sum' => round(array_sum($nums), 2),
                'mean' => round(array_sum($nums) / $count, 2),
                'median' => round($count % 2 ? $nums[intdiv($count, 2)] : ($nums[$count / 2 - 1] + $nums[$count / 2]) / 2, 2),
                'negatives' => count(array_filter($nums, fn (float $v) => $v < 0)),
            ];
        }

        if ($type === 'date' && $nonNull !== []) {
            $dates = $nonNull;
            sort($dates);
            $profile += ['min' => $dates[0], 'max' => end($dates)];
        }

        if (in_array($type, ['category', 'code', 'boolean'], true) && $nonNull !== []) {
            $counts = array_count_values(array_map(fn ($v) => is_bool($v) ? ($v ? 'ใช่' : 'ไม่ใช่') : (string) $v, $nonNull));
            arsort($counts);
            $profile['top'] = array_slice($counts, 0, 10, true);
        }

        return $profile;
    }
}
