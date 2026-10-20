<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['ai_conversation_id', 'role', 'payload_hash', 'content', 'model', 'input_tokens', 'output_tokens', 'key_source', 'from_cache', 'latency_ms', 'error', 'created_at'])]
class AiMessage extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['from_cache' => 'boolean'];
    }
}
