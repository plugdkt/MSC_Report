<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ขั้นตอนถาม-ตอบก่อนสร้างรายงานของ dataset หนึ่งชุด
 */
#[Fillable(['user_id', 'dataset_id', 'mode', 'status', 'questions', 'answers', 'understanding', 'notices', 'error_message', 'dashboard_id', 'send_samples'])]
class ReportDraft extends Model
{
    protected function casts(): array
    {
        return [
            'questions' => 'array',
            'answers' => 'array',
            'notices' => 'array',
            'send_samples' => 'boolean',
        ];
    }

    public function dataset(): BelongsTo
    {
        return $this->belongsTo(Dataset::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function addNotice(string $message): void
    {
        $this->notices = array_values(array_unique([...($this->notices ?? []), $message]));
    }
}
