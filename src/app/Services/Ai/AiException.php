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

    public static function fromStatus(int $status, string $body): self
    {
        return match (true) {
            in_array($status, [401, 403], true) => new self(self::INVALID_KEY, 'API Key ใช้ไม่ได้หรือไม่มีสิทธิ์เรียกใช้ AI — กรุณาตรวจสอบ key ในหน้า "AI ของฉัน"'),
            $status === 429 && preg_match('/quota|insufficient|exceed|limit reached|credit/i', $body) === 1 => new self(self::QUOTA, 'token ของคุณหมดโควต้าแล้ว งานที่ทำไว้ยังอยู่ครบ ใช้โหมดไม่ใช้ AI ต่อได้'),
            $status === 429 || $status >= 500 => new self(self::TRANSIENT, 'ระบบ AI ไม่ว่างหรือขัดข้องชั่วคราว กรุณาลองใหม่อีกครั้ง'),
            default => new self(self::BAD_REQUEST, "ระบบ AI ปฏิเสธคำขอ (HTTP {$status}) — กรุณาแจ้งผู้ดูแลระบบตรวจการตั้งค่า AI"),
        };
    }
}
