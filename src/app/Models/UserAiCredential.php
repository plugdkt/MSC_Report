<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

/**
 * API Key ของผู้ใช้แต่ละคน (spec §6.4) — ค่าเต็มถอดรหัสได้เฉพาะตอนเรียก AI
 */
#[Fillable(['user_id', 'encrypted_key', 'key_last4', 'key_fingerprint', 'status', 'monthly_token_budget', 'quota', 'last_verified_at', 'last_used_at', 'last_error'])]
#[Hidden(['encrypted_key'])]
class UserAiCredential extends Model
{
    public const STATUS_LABELS = [
        'active' => 'ใช้งานได้',
        'invalid' => 'ใช้ไม่ได้ (key ไม่ถูกต้อง)',
        'quota_exceeded' => 'token หมดโควต้า',
        'revoked' => 'ถูกยกเลิกโดยผู้ดูแลระบบ',
    ];

    protected function casts(): array
    {
        return [
            'quota' => 'array',
            'last_verified_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * ทำความสะอาด key ที่วางมา: ตัด "Bearer ", เครื่องหมายคำพูด, ช่องว่าง/ขึ้นบรรทัด
     */
    public static function clean(string $pasted): string
    {
        $key = trim($pasted, " \t\n\r\0\x0B\"'`");
        $key = preg_replace('/^(authorization:\s*)?bearer\s+/i', '', $key) ?? $key;

        return preg_replace('/\s+/', '', $key) ?? $key;
    }

    public static function attributesFor(string $plainKey): array
    {
        $plainKey = self::clean($plainKey);

        return [
            'encrypted_key' => Crypt::encryptString($plainKey),
            'key_last4' => mb_substr($plainKey, -4),
            'key_fingerprint' => hash('sha256', $plainKey),
        ];
    }

    /**
     * @param  array{quota:int, used:int, remaining:int}|null  $quota
     */
    public function rememberQuota(string $model, ?array $quota): void
    {
        if ($quota === null) {
            return;
        }
        $all = $this->quota ?? [];
        $all[$model] = $quota + ['at' => now()->toIso8601String()];
        $this->forceFill(['quota' => $all])->save();
    }

    public function plainKey(): string
    {
        return Crypt::decryptString($this->encrypted_key);
    }

    public function maskedKey(): string
    {
        return '••••'.$this->key_last4;
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
