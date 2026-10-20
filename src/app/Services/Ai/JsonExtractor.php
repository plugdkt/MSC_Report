<?php

namespace App\Services\Ai;

/**
 * แปลงคำตอบของ AI เป็น JSON โดยซ่อมเล็กน้อยฝั่ง PHP ก่อน (ตัด markdown fence, ข้อความนำ, comma เกิน) — spec §6.1
 */
class JsonExtractor
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decode(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }
        $json = substr($text, $start, $end - $start + 1);

        $data = json_decode($json, true);
        if (! is_array($data)) {
            $data = json_decode(preg_replace('/,\s*([\]}])/', '$1', $json) ?? $json, true);
        }

        return is_array($data) ? $data : null;
    }
}
