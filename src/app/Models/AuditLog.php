<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'username', 'action', 'subject_type', 'subject_id', 'summary', 'meta', 'ip'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @var array<string, string> */
    public const ACTIONS = [
        'auth.login' => 'เข้าสู่ระบบ',
        'auth.login_failed' => 'เข้าสู่ระบบไม่สำเร็จ',
        'auth.logout' => 'ออกจากระบบ',
        'dataset.upload' => 'อัปโหลดไฟล์',
        'dataset.import' => 'นำเข้าข้อมูล',
        'dataset.delete' => 'ลบข้อมูล',
        'ai.consent' => 'ยอมรับการใช้ AI',
        'ai.key_saved' => 'บันทึก API Key',
        'ai.key_deleted' => 'ลบ API Key',
        'ai.key_revoked' => 'Admin ยกเลิก API Key',
        'ai.settings' => 'แก้ตั้งค่า AI',
        'report.created' => 'สร้างรายงาน',
        'report.saved' => 'บันทึกรายงาน',
        'report.reverted' => 'ย้อนเวอร์ชัน',
        'report.deleted' => 'ลบรายงาน',
        'share.added' => 'แชร์รายงาน',
        'share.removed' => 'ยกเลิกการแชร์',
        'export.created' => 'สั่งส่งออก',
        'export.downloaded' => 'ดาวน์โหลดไฟล์ส่งออก',
        'admin.user_updated' => 'แก้สิทธิ์ผู้ใช้',
        'admin.codes' => 'แก้ตารางรหัส',
        'admin.settings' => 'แก้ตั้งค่าระบบ',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }
}
