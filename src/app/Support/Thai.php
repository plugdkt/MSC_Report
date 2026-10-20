<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * การแสดงผลแบบไทย: วันที่ พ.ศ., ตัวเลขเงิน
 */
class Thai
{
    private const MONTHS = ['', 'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    public static function date(DateTimeInterface|string|null $date, bool $withTime = false): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        $d = $date instanceof DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);
        $text = $d->day.' '.self::MONTHS[$d->month].' '.($d->year + 543);

        return $withTime ? $text.' '.$d->format('H:i').' น.' : $text;
    }

    public static function money(float|int|string|null $value): string
    {
        return $value === null || $value === '' ? '—' : number_format((float) $value, 2);
    }

    /**
     * ตัวเลข KPI สำหรับแสดงบนการ์ด: จำนวนเงินตั้งแต่ 1 ล้านแสดงเป็นล้านบาท พร้อมค่าเต็มเป็นบรรทัดรอง
     *
     * @return array{value:string, unit:string, sub:?string}
     */
    public static function kpi(float|int|null $value, ?string $unit): array
    {
        if ($value === null) {
            return ['value' => '—', 'unit' => '', 'sub' => null];
        }
        if ($unit === 'บาท') {
            return abs($value) >= 1e6
                ? ['value' => number_format($value / 1e6, 2), 'unit' => 'ล้านบาท', 'sub' => number_format($value, 2).' บาท']
                : ['value' => number_format($value, 2), 'unit' => 'บาท', 'sub' => null];
        }
        if ($unit === '%') {
            return ['value' => number_format($value, 1).'%', 'unit' => '', 'sub' => null];
        }

        return ['value' => number_format($value, floor($value) == $value ? 0 : 2), 'unit' => (string) $unit, 'sub' => null];
    }

    public static function fileSize(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024, 0).' KB';
    }
}
