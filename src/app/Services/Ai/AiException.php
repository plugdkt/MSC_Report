<?php

namespace App\Services\Ai;

use RuntimeException;
use Throwable;

/**
 * ข้อความ (getMessage) แสดงให้ผู้ใช้เห็นได้ — ห้ามใส่ API key หรือ payload
 */
class AiException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const NO_KEY = 'no_key';

    public const INVALID_KEY = 'invalid_key';

    public const QUOTA = 'quota';

    public const BUDGET = 'budget';

    public const TRANSIENT = 'transient';

    public const BAD_RESPONSE = 'bad_response';

    public const BAD_REQUEST = 'bad_request';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?string $rawText = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromStatus(int $status, string $body, string $model = ''): self
    {
        $detail = ' ['.trim("HTTP {$status} ".self::gatewayMessage($body)).']';
        $modelProblem = preg_match('/model|permission|not allowed|access|forbidden/i', self::gatewayMessage($body)) === 1;

        return match (true) {
            $status === 401 => new self(self::INVALID_KEY, 'API Key ไม่ถูกต้อง — ตรวจว่าคัดลอกครบ ไม่มีช่องว่าง และยังไม่หมดอายุ'.$detail),
            $status === 403 && $modelProblem => new self(self::INVALID_KEY, "API Key นี้ไม่มีสิทธิ์ใช้โมเดล {$model} — ขอสิทธิ์เพิ่ม หรือให้ผู้ดูแลระบบเลือกโมเดลอื่น".$detail),
            $status === 403 => new self(self::INVALID_KEY, 'API Key ไม่มีสิทธิ์เรียกใช้ AI'.$detail),
            $status === 429 && preg_match('/quota|insufficient|exceed|limit reached|credit|budget/i', $body) === 1 => new self(self::QUOTA, 'token ของคุณหมดโควต้าแล้ว งานที่ทำไว้ยังอยู่ครบ ใช้โหมดไม่ใช้ AI ต่อได้'.$detail),
            $status === 429 || $status >= 500 => new self(self::TRANSIENT, 'ระบบ AI ไม่ว่างหรือขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง'.$detail),
            in_array($status, [400, 404, 422], true) && $modelProblem => new self(self::BAD_REQUEST, "gateway ไม่รู้จักหรือไม่รองรับโมเดล {$model} — ให้ผู้ดูแลระบบเลือกโมเดลอื่นในหน้าตั้งค่า AI".$detail),
            default => new self(self::BAD_REQUEST, 'ระบบ AI ปฏิเสธคำขอ — กรุณาแจ้งผู้ดูแลระบบตรวจการตั้งค่า AI'.$detail),
        };
    }

    /**
     * ข้อความ error จาก gateway (ตัดสั้น ไม่มีข้อมูลลับ) เพื่อช่วยวิเคราะห์ปัญหา
     */
    public static function gatewayMessage(string $body): string
    {
        $json = json_decode($body, true);
        $message = match (true) {
            is_string($json['error'] ?? null) => $json['error'],
            is_string($json['error']['message'] ?? null) => $json['error']['message'],
            is_string($json['message'] ?? null) => $json['message'],
            is_string($json['detail'] ?? null) => $json['detail'],
            default => strip_tags($body),
        };

        return mb_strimwidth(trim(preg_replace('/\s+/u', ' ', preg_replace('/(sk|key)[-_][A-Za-z0-9_\-]{6,}/', '***', $message) ?? '') ?? ''), 0, 160, '…');
    }
}
