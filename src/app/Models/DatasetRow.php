<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['dataset_id', 'row_index', 'source_row', 'data'])]
class DatasetRow extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['data' => 'array'];
    }
}
