<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dataset_id', 'position', 'slug', 'source_name', 'display_name', 'inferred_type', 'user_type', 'is_pii', 'is_hidden', 'profile'])]
class DatasetColumn extends Model
{
    public const TYPES = [
        'currency' => 'จำนวนเงิน',
        'number' => 'ตัวเลข',
        'integer' => 'จำนวนเต็ม',
        'percent' => 'ร้อยละ',
        'date' => 'วันที่',
        'category' => 'หมวดหมู่',
        'code' => 'รหัส',
        'text' => 'ข้อความ',
        'boolean' => 'ใช่/ไม่ใช่',
    ];

    public const NUMERIC_TYPES = ['currency', 'number', 'integer', 'percent'];

    protected function casts(): array
    {
        return [
            'is_pii' => 'boolean',
            'is_hidden' => 'boolean',
            'profile' => 'array',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function type(): string
    {
        return $this->user_type ?? $this->inferred_type;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type()] ?? $this->type();
    }

    public function isNumeric(): bool
    {
        return in_array($this->type(), self::NUMERIC_TYPES, true);
    }
}
