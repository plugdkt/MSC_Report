<?php

namespace App\Services\Import;

/**
 * แปลงค่าที่พบบ่อยในไฟล์ภาษาไทย: เลขไทย, ตัวเลขมี , ฿ %, วันที่ พ.ศ.
 */
class ValueNormalizer
{
    private const THAI_DIGITS = ['๐' => '0', '๑' => '1', '๒' => '2', '๓' => '3', '๔' => '4', '๕' => '5', '๖' => '6', '๗' => '7', '๘' => '8', '๙' => '9'];

    private const THAI_MONTHS = [
        'ม.ค.' => 1, 'มกราคม' => 1, 'ก.พ.' => 2, 'กุมภาพันธ์' => 2, 'มี.ค.' => 3, 'มีนาคม' => 3,
        'เม.ย.' => 4, 'เมษายน' => 4, 'พ.ค.' => 5, 'พฤษภาคม' => 5, 'มิ.ย.' => 6, 'มิถุนายน' => 6,
        'ก.ค.' => 7, 'กรกฎาคม' => 7, 'ส.ค.' => 8, 'สิงหาคม' => 8, 'ก.ย.' => 9, 'กันยายน' => 9,
        'ต.ค.' => 10, 'ตุลาคม' => 10, 'พ.ย.' => 11, 'พฤศจิกายน' => 11, 'ธ.ค.' => 12, 'ธันวาคม' => 12,
    ];

    public static function cleanText(string $value): string
    {
        $value = strtr($value, self::THAI_DIGITS);
        $value = str_replace(["\u{00A0}", "\u{200B}", "\r"], [' ', '', ''], $value);

        return trim(preg_replace('/[ \t]+/u', ' ', $value) ?? $value);
    }

    /**
     * "1,234.50", "฿1,234", "(1,234)", "12%" → ตัวเลข ; คืน null ถ้าไม่ใช่ตัวเลข
     */
    public static function parseNumber(string|int|float|null $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $s = self::cleanText($value);
        if ($s === '' || $s === '-') {
            return null;
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/u', $s, $m)) {
            $negative = true;
            $s = $m[1];
        }
        $s = str_replace([',', '฿', 'บาท', '%', ' '], '', $s);
        if (! is_numeric($s)) {
            return null;
        }

        $n = (float) $s;

        return $negative ? -$n : $n;
    }

    /**
     * "15/03/2569", "15-3-2026", "2569-03-15", "15 มี.ค. 2569" → "2026-03-15"
     */
    public static function parseDate(string $value): ?string
    {
        $s = self::cleanText($value);

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/', $s, $m)) {
            return self::ymd((int) $m[3], (int) $m[2], (int) $m[1]);
        }
        if (preg_match('/^(\d{4})[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/', $s, $m)) {
            return self::ymd((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})\s*([ก-๙\.]+)\s*(\d{2,4})$/u', $s, $m) && isset(self::THAI_MONTHS[$m[2]])) {
            return self::ymd((int) $m[3], self::THAI_MONTHS[$m[2]], (int) $m[1]);
        }

        return null;
    }

    private static function ymd(int $year, int $month, int $day): ?string
    {
        if ($year < 100) {
            $year += $year >= 40 ? 2500 : 2000; // "69" = พ.ศ. 2569
        }
        if ($year > 2400) {
            $year -= 543;
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }
}
