<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code_set_id', 'code', 'label', 'parent_id', 'sort'])]
class CodeValue extends Model
{
    public function codeSet(): BelongsTo
    {
        return $this->belongsTo(CodeSet::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
