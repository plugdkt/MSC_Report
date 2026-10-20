<?php

namespace Tests\Support;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserAiCredential;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;

/**
 * ตั้งค่า AI ปลอมสำหรับ test (OpenAI-compatible) และสร้างคำตอบตามรูปแบบจริง
 */
trait AiFake
{
    protected const AI_URL = 'https://ai.test/v1/chat/completions';

    protected function configureAi(string $format = 'openai'): void
    {
        Setting::put('ai.format', $format);
        Setting::put('ai.base_url', 'https://ai.test/v1');
        Setting::put('ai.model_smart', 'org-model-large');
        Setting::put('ai.model_fast', 'org-model-small');
        Setting::put('ai.auth_style', 'bearer');
        config(['ai.retry_delay_ms' => 0]);
    }

    protected function giveKey(User $user, string $key = 'sk-test-1234567890abcd'): UserAiCredential
    {
        return UserAiCredential::create(['user_id' => $user->id, 'status' => 'active'] + UserAiCredential::attributesFor($key));
    }

    /**
     * @param  array<string, mixed>|string  $content
     */
    protected static function chat(array|string $content, int $in = 1200, int $out = 300): PromiseInterface
    {
        return Http::response([
            'model' => 'org-model-large',
            'choices' => [['message' => ['role' => 'assistant', 'content' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE)]]],
            'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out],
        ]);
    }
}
