<?php

namespace App\Services\Ai;

/**
 * ประมาณจำนวน token ก่อนเรียก AI — วัดจริงกับ UP AI Connect (claude-sonnet-5.5): profile JSON ภาษาไทยผสมตัวเลข ≈ 1.5 ตัวอักษร/token
 */
class TokenEstimator
{
    public const CHARS_PER_TOKEN = 1.5;

    public static function estimate(string $text): int
    {
        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }
}
