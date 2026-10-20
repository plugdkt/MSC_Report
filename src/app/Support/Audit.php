<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * บันทึกเหตุการณ์สำคัญลง audit_logs (spec FR-10, §10) — ห้ามใส่ข้อมูลลับ (API key, token, เนื้อหาข้อมูลดิบ) ใน $meta
 */
class Audit
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public static function log(string $action, ?Model $subject = null, array $meta = [], ?User $user = null, ?string $summary = null): void
    {
        try {
            $user ??= auth()->user();
            AuditLog::create([
                'user_id' => $user?->id,
                'username' => $user?->username ?? ($meta['username'] ?? null),
                'action' => $action,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id' => $subject?->getKey(),
                'summary' => $summary ? mb_substr($summary, 0, 250) : null,
                'meta' => $meta ?: null,
                'ip' => request()?->ip(),
            ]);
        } catch (Throwable $e) {
            report($e); // การบันทึก audit ล้มเหลวต้องไม่ทำให้งานหลักล้ม
        }
    }
}
