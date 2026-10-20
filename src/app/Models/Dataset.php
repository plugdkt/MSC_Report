<?php

namespace App\Models;

use Database\Factories\DatasetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id', 'name', 'original_filename', 'stored_path', 'file_hash', 'file_size', 'status', 'inspection',
    'sheet_name', 'header_row_start', 'header_row_end', 'title_lines', 'row_count', 'excluded_rows', 'quality',
    'error_message', 'period_label', 'expires_at',
])]
class Dataset extends Model
{
    /** @use HasFactory<DatasetFactory> */
    use HasFactory;

    public const STATUS_LABELS = [
        'uploaded' => 'อัปโหลดแล้ว',
        'inspected' => 'รอเลือกชีต',
        'importing' => 'กำลังนำเข้า',
        'ready' => 'พร้อมใช้งาน',
        'failed' => 'นำเข้าไม่สำเร็จ',
    ];

    protected function casts(): array
    {
        return [
            'inspection' => 'array',
            'title_lines' => 'array',
            'excluded_rows' => 'array',
            'quality' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function columns(): HasMany
    {
        return $this->hasMany(DatasetColumn::class)->orderBy('position');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(DatasetRow::class)->orderBy('row_index');
    }

    public function statusLabel(): string
    {
        return self::STATUS_LABELS[$this->status] ?? $this->status;
    }
}
