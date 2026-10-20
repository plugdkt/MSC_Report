<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'dataset_id', 'stage'])]
class AiConversation extends Model
{
    public function messages(): HasMany
    {
        return $this->hasMany(AiMessage::class);
    }
}
