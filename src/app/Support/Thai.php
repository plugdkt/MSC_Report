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

    public static function fileSize(int $bytes): string
    {
        return $bytes >= 1048576 ? number_format($bytes / 1048576, 1).' MB' : number_format($bytes / 1024, 0).' KB';
    }
}
