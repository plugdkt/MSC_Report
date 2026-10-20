<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dashboard_id', 'dashboard_version_id', 'user_id', 'format', 'target', 'options', 'status', 'file_path', 'filename', 'file_size', 'duration_ms', 'error_message', 'expires_at'])]
class Export extends Model
{
    public const TARGETS = [
        'report' => 'รายงานฉบับเต็ม',
        'infographic' => 'อินโฟกราฟิก 1 หน้า',
        'dashboard' => 'ภาพ Dashboard ทั้งหน้า',
    ];

    public const STATUS_LABELS = [
        'queued' => 'รอคิว',
        'processing' => 'กำลังสร้างไฟล์',
        'done' => 'พร้อมดาวน์โหลด',
        'failed' => 'ไม่สำเร็จ',
    ];

    protected function casts(): array
    {
        return ['options' => 'array', 'expires_at' => 'datetime'];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DashboardVersion::class, 'dashboard_version_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'done' && $this->expires_at->isFuture();
    }

    public function label(): string
    {
        return self::TARGETS[$this->target].' ('.strtoupper($this->format).($this->options['scale'] ?? null ? ' '.$this->options['scale'].'x' : '').')';
    }

    public function statusLabel(): string
    {
        return $this->status === 'done' && ! $this->expires_at->isFuture() ? 'หมดอายุแล้ว' : self::STATUS_LABELS[$this->status];
    }
}
