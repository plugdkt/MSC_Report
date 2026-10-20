<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * ส่งคำขอไปยัง AI ตามรูปแบบ API ที่ Admin ตั้งไว้ (OpenAI-compatible หรือ Anthropic-compatible)
 */
class AiTransport
{
    public function __construct(private readonly AiConfig $config) {}

    public function send(string $apiKey, AiRequest $request): AiResponse
    {
        if (! $this->config->isConfigured()) {
            throw new AiException(AiException::NOT_CONFIGURED, 'ผู้ดูแลระบบยังไม่ได้ตั้งค่า AI ขององค์กร (Base URL / โมเดล)');
        }

        $model = $this->config->model($request->tier);
        $maxTokens = $request->maxTokens ?? (int) config('ai.max_output_tokens.'.$request->stage, 1500);

        try {
            $response = $this->config->get('format') === 'anthropic'
                ? $this->anthropic($apiKey, $model, $maxTokens, $request)
                : $this->openai($apiKey, $model, $maxTokens, $request);
        } catch (ConnectionException $e) {
            throw new AiException(AiException::TRANSIENT, 'เชื่อมต่อระบบ AI ไม่ได้ (หมดเวลา/เครือข่าย) กรุณาลองใหม่อีกครั้ง', previous: $e);
        }

        if ($response->failed()) {
            throw AiException::fromStatus($response->status(), (string) $response->body(), $model);
        }

        return $this->config->get('format') === 'anthropic'
            ? $this->parseAnthropic($response, $model)
            : $this->parseOpenAi($response, $model);
    }

    private function http(string $apiKey): PendingRequest
    {
        $http = Http::acceptJson()->asJson()
            ->timeout($this->config->get('timeout'))
            ->withOptions(['verify' => (bool) config('ai.verify_ssl')]);

        return match ($this->config->get('auth_style')) {
            'x-api-key' => $http->withHeaders(['x-api-key' => $apiKey]),
            'api-key' => $http->withHeaders(['api-key' => $apiKey]),
            default => $http->withToken($apiKey),
        };
    }

    private function openai(string $apiKey, string $model, int $maxTokens, AiRequest $request): Response
    {
        $body = [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $request->system], ...$request->messages],
            'max_tokens' => $maxTokens,
            'temperature' => 0.2,
        ];
        if ($this->config->get('json_mode') && $request->stage !== 'test') {
            $body['response_format'] = ['type' => 'json_object'];
        }

        return $this->http($apiKey)->post($this->config->get('base_url').'/chat/completions', $body);
    }

    private function anthropic(string $apiKey, string $model, int $maxTokens, AiRequest $request): Response
    {
        $http = $this->config->get('auth_style') === 'bearer'
            ? $this->http($apiKey)->withHeaders(['x-api-key' => $apiKey])
            : $this->http($apiKey);

        return $http->withHeaders(['anthropic-version' => '2023-06-01'])
            ->post($this->config->get('base_url').'/messages', [
                'model' => $model,
                'system' => $request->system,
                'messages' => $request->messages,
                'max_tokens' => $maxTokens,
                'temperature' => 0.2,
            ]);
    }

    private function parseOpenAi(Response $response, string $model): AiResponse
    {
        $text = $response->json('choices.0.message.content');
        if (! is_string($text) || trim($text) === '') {
            if (! is_array($response->json('choices'))) {
                throw new AiException(AiException::BAD_RESPONSE, 'ระบบ AI ตอบกลับในรูปแบบที่ไม่รู้จัก — กรุณาแจ้งผู้ดูแลระบบตรวจ "รูปแบบ API"');
            }
            if ($response->json('choices.0.finish_reason') === 'length') {
                throw new AiException(AiException::BAD_RESPONSE, "โมเดล {$model} ใช้ token คิดจนหมดก่อนตอบ — ให้ผู้ดูแลระบบเพิ่ม max_tokens หรือเลือกโมเดลอื่น", '');
            }
            $text = '';
        }

        $quota = $response->json('model_quota');

        return new AiResponse(
            $text,
            (int) $response->json('usage.prompt_tokens', 0),
            (int) $response->json('usage.completion_tokens', 0),
            (string) $response->json('model', $model),
            quota: is_array($quota) && isset($quota['daily_quota_tokens']) ? [
                'quota' => (int) $quota['daily_quota_tokens'],
                'used' => (int) ($quota['daily_usage_tokens'] ?? 0),
                'remaining' => (int) ($quota['daily_remaining_tokens'] ?? 0),
            ] : null,
        );
    }

    private function parseAnthropic(Response $response, string $model): AiResponse
    {
        $text = collect($response->json('content', []))->where('type', 'text')->pluck('text')->implode('');
        if ($text === '') {
            throw new AiException(AiException::BAD_RESPONSE, 'ระบบ AI ตอบกลับในรูปแบบที่ไม่รู้จัก — กรุณาแจ้งผู้ดูแลระบบตรวจ "รูปแบบ API"');
        }

        return new AiResponse($text, (int) $response->json('usage.input_tokens', 0), (int) $response->json('usage.output_tokens', 0), (string) $response->json('model', $model));
    }
}
