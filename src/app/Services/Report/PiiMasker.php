<?php

namespace App\Services\Report;

/**
 * ปิดบังข้อมูลส่วนบุคคลก่อนส่งให้ AI (spec FR-7.1)
 */
class PiiMasker
{
    public static function mask(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }
        $v = trim($value);

        $digits = preg_replace('/\D/', '', $v);
        if (strlen($digits) === 13 && preg_match('/^[\d\-\s]+$/', $v)) {
            return $digits[0].'-xxxx-xxxxx-xx-'.$digits[12];
        }
        if (preg_match('/^([\w.+-])[\w.+-]*@([\w.-]+)$/u', $v, $m)) {
            return $m[1].'***@'.$m[2];
        }
        if (preg_match('/^0\d[\d\-\s]{7,11}$/', $v)) {
            return substr($digits, 0, 3).'-xxx-x'.substr($digits, -3);
        }

        $words = preg_split('/\s+/u', $v);
        if (count($words) >= 2) {
            return $words[0].' '.mb_substr($words[1], 0, 1).'.';
        }

        return mb_substr($v, 0, 1).'***';
    }
}
