<?php

namespace App\Services\Ai;

use App\Models\Setting;

/**
 * ค่าตั้ง AI ที่ใช้งานจริง: ค่าจาก Admin (settings) ทับค่าเริ่มต้นใน config/ai.php
 */
class AiConfig
{
    public const KEYS = ['format', 'base_url', 'auth_style', 'model_smart', 'model_fast', 'json_mode', 'timeout'];

    public function get(string $key): mixed
    {
        $value = Setting::get('ai.'.$key, config('ai.'.$key));

        return match ($key) {
            'json_mode' => filter_var($value, FILTER_VALIDATE_BOOL),
            'timeout' => max(5, (int) $value),
            'base_url' => $value ? rtrim((string) $value, '/') : null,
            default => $value,
        };
    }

    public function isConfigured(): bool
    {
        return filled($this->get('base_url')) && filled($this->get('model_smart'));
    }

    public function model(string $tier): string
    {
        return (string) ($tier === 'fast' ? ($this->get('model_fast') ?: $this->get('model_smart')) : $this->get('model_smart'));
    }

    public function sharedEnabled(): bool
    {
        return filter_var(Setting::get('ai.shared_enabled', false), FILTER_VALIDATE_BOOL) && filled(Setting::get('ai.shared_key'));
    }

    public function sharedKey(): ?string
    {
        return Setting::get('ai.shared_key');
    }

    public function sharedMonthlyCap(): int
    {
        return (int) Setting::get('ai.shared_monthly_cap', 20000);
    }
}
