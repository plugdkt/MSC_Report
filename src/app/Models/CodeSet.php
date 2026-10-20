<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['key', 'name', 'description', 'column_pattern', 'sort'])]
class CodeSet extends Model
{
    public function values(): HasMany
    {
        return $this->hasMany(CodeValue::class)->orderBy('sort')->orderBy('code');
    }

    public function groupRules(): HasMany
    {
        return $this->hasMany(CodeGroupRule::class)->orderBy('sort')->orderBy('id');
    }

    /**
     * หัวคอลัมน์นี้ควรผูกกับชุดรหัสนี้หรือไม่
     */
    public function matchesColumn(string $header): bool
    {
        if (blank($this->column_pattern) || trim($header) === '') {
            return false;
        }

        return @preg_match('/'.str_replace('/', '\/', $this->column_pattern).'/iu', trim($header)) === 1;
    }
}
