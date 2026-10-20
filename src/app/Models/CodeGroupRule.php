<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['code_set_id', 'match_type', 'pattern', 'group_label', 'sort'])]
class CodeGroupRule extends Model
{
    public const TYPES = ['prefix' => 'ขึ้นต้นด้วย', 'exact' => 'ตรงทั้งหมด', 'regex' => 'Regex'];

    public function codeSet(): BelongsTo
    {
        return $this->belongsTo(CodeSet::class);
    }

    public function matches(string $value): bool
    {
        $value = trim($value);

        return match ($this->match_type) {
            'exact' => $value === $this->pattern,
            'regex' => @preg_match('/'.str_replace('/', '\/', $this->pattern).'/iu', $value) === 1,
            default => str_starts_with($value, $this->pattern),
        };
    }
}
