<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['dashboard_id', 'version', 'spec', 'computed_data', 'executive_summary', 'source', 'created_by', 'note'])]
class DashboardVersion extends Model
{
    protected function casts(): array
    {
        return [
            'spec' => 'array',
            'computed_data' => 'array',
            'executive_summary' => 'array',
        ];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }
}
