<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * ค่าตั้งของระบบที่ Admin แก้ได้ (เช่น AI provider) — ค่าลับเก็บแบบเข้ารหัส
 */
#[Fillable(['key', 'value', 'is_encrypted'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    private const CACHE_KEY = 'settings.all';

    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => self::all()->mapWithKeys(fn (self $s) => [$s->key => [$s->value, $s->is_encrypted]])->all());
        if (! array_key_exists($key, $all) || $all[$key][0] === null) {
            return $default;
        }
        [$value, $encrypted] = $all[$key];

        return $encrypted ? Crypt::decryptString($value) : $value;
    }

    public static function put(string $key, mixed $value, bool $encrypted = false): void
    {
        self::updateOrCreate(['key' => $key], [
            'value' => $value === null || $value === '' ? null : ($encrypted ? Crypt::encryptString((string) $value) : (string) $value),
            'is_encrypted' => $encrypted,
        ]);
        Cache::forget(self::CACHE_KEY);
    }
}
