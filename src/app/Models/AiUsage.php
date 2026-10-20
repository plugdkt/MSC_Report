<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['user_id', 'period', 'key_source', 'input_tokens', 'output_tokens', 'request_count'])]
class AiUsage extends Model
{
    public static function record(int $userId, string $keySource, int $input, int $output): void
    {
        $row = self::firstOrCreate(['user_id' => $userId, 'period' => now()->format('Y-m'), 'key_source' => $keySource]);
        $row->increment('input_tokens', $input);
        $row->increment('output_tokens', $output);
        $row->increment('request_count');
    }

    public static function monthTotal(int $userId, ?string $keySource = null): int
    {
        return (int) self::where('user_id', $userId)->where('period', now()->format('Y-m'))
            ->when($keySource, fn ($q) => $q->where('key_source', $keySource))
            ->selectRaw('coalesce(sum(input_tokens + output_tokens), 0) as total')->value('total');
    }
}
