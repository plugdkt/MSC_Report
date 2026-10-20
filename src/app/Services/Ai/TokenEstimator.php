<?php

namespace App\Services\Ai;

/**
 * ประมาณจำนวน token ก่อนเรียก AI (ภาษาไทยผสมอังกฤษ ≈ 3 ตัวอักษร/token — ปรับหลังวัดจริง, spec §6.5)
 */
class TokenEstimator
{
    public const CHARS_PER_TOKEN = 3.0;

    public static function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
