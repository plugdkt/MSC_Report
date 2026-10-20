<?php

namespace App\Services\Ai;

use App\Models\AiConversation;
use App\Models\AiUsage;
use App\Models\Dataset;
use App\Models\User;
use App\Models\UserAiCredential;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * จุดเดียวที่เรียก AI: เลือก key (ส่วนตัว → shared), ตรวจงบ token, cache, จำกัดการเรียกพร้อมกันต่อ key,
 * retry เฉพาะ error ชั่วคราว, บันทึกการใช้ token (spec §6.4, §6.5, FR-7.4)
 */
class AiGateway
{
    public function __construct(
        private readonly AiTransport $transport,
        private readonly AiConfig $config,
    ) {}

    /**
     * สถานะที่ผู้ใช้จะเจอเมื่อเรียก AI — ใช้แสดงในหน้าจอก่อนกด
     *
     * @return array{available:bool, source:?string, reason:?string}
     */
    public function availability(User $user): array
    {
        if (! $this->config->isConfigured()) {
            return ['available' => false, 'source' => null, 'reason' => 'ผู้ดูแลระบบยังไม่ได้ตั้งค่า AI ขององค์กร'];
        }
        try {
            [, $source] = $this->resolveKey($user, 0);

            return ['available' => true, 'source' => $source, 'reason' => null];
        } catch (AiException $e) {
            return ['available' => false, 'source' => null, 'reason' => $e->getMessage()];
        }
    }

    /**
     * ทดสอบ key ก่อนบันทึก (ยังไม่บันทึก usage ของผู้ใช้)
     */
    public function testKey(string $apiKey): AiResponse
    {
        return $this->transport->send($apiKey, new AiRequest('test', 'Reply with one word.', [['role' => 'user', 'content' => 'Reply: ok']], 'fast'));
    }

    /**
     * เรียก AI แล้วคืนข้อความ (cache ตาม stage + โมเดล + ข้อความที่ส่ง)
     */
    public function complete(User $user, AiRequest $request, ?Dataset $dataset = null, bool $useCache = true): AiResponse
    {
        $model = $this->config->model($request->tier);
        $cacheKey = hash('sha256', json_encode([$request->stage, $model, $request->system, $request->messages], JSON_UNESCAPED_UNICODE));

        if ($useCache && ($cached = $this->cached($cacheKey))) {
            $this->log($user, $dataset, $request, $cached, $cacheKey);

            return $cached;
        }

        [$apiKey, $source, $credential] = $this->resolveKey($user, $request->estimatedInputTokens() + (int) config('ai.max_output_tokens.'.$request->stage, 1500));

        $lock = Cache::lock('ai-key:'.hash('sha256', $apiKey), $this->config->get('timeout') * (config('ai.retries') + 1) + 10);
        $lock->block(min(60, $this->config->get('timeout')));

        $started = hrtime(true);
        try {
            $response = $this->sendWithRetry($apiKey, $request);
        } catch (AiException $e) {
            $this->markCredential($credential, $e);
            $this->log($user, $dataset, $request, null, $cacheKey, $source, $e->kind);
            throw $e;
        } finally {
            $lock->release();
        }

        $response = new AiResponse($response->text, $response->inputTokens ?: $request->estimatedInputTokens(), $response->outputTokens ?: TokenEstimator::estimate($response->text), $response->model, false, $source, $response->quota, $response->truncated);
        AiUsage::record($user->id, $source, $response->inputTokens, $response->outputTokens);
        $credential?->forceFill(['last_used_at' => now(), 'status' => 'active', 'last_error' => null])->save();
        $credential?->rememberQuota($response->model, $response->quota);
        $this->log($user, $dataset, $request, $response, $cacheKey, $source, null, (int) ((hrtime(true) - $started) / 1e6));

        return $response;
    }

    /**
     * เก็บคำตอบที่ผ่านการตรวจแล้วไว้ใช้ซ้ำ (ไม่ cache คำตอบที่ใช้ไม่ได้)
     */
    public function storeCache(AiRequest $request, AiResponse $response): void
    {
        if ($response->fromCache) {
            return;
        }
        $model = $this->config->model($request->tier);
        $key = hash('sha256', json_encode([$request->stage, $model, $request->system, $request->messages], JSON_UNESCAPED_UNICODE));
        DB::table('ai_response_cache')->upsert([[
            'cache_key' => $key,
            'stage' => $request->stage,
            'response' => json_encode(['text' => $response->text, 'model' => $response->model, 'tokens' => $response->totalTokens()], JSON_UNESCAPED_UNICODE),
            'tokens_saved' => $response->totalTokens(),
            'hit_count' => 0,
            'expires_at' => now()->addDays(config('ai.cache_days')),
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['cache_key'], ['response', 'tokens_saved', 'expires_at', 'updated_at']);
    }

    /**
     * @return array{0:string, 1:'personal'|'shared', 2:?UserAiCredential}
     */
    private function resolveKey(User $user, int $estimatedTokens): array
    {
        $credential = $user->aiCredential;

        if ($credential && $credential->status === 'active') {
            $budget = $credential->monthly_token_budget;
            if ($budget !== null && AiUsage::monthTotal($user->id, 'personal') + $estimatedTokens > $budget) {
                throw new AiException(AiException::BUDGET, 'ถึงงบ token ต่อเดือนที่คุณตั้งไว้แล้ว ('.number_format($budget).' token) — ปรับงบได้ที่หน้า "AI ของฉัน"');
            }

            return [$credential->plainKey(), 'personal', $credential];
        }

        if ($this->config->sharedEnabled()) {
            $cap = $this->config->sharedMonthlyCap();
            if (AiUsage::monthTotal($user->id, 'shared') + $estimatedTokens > $cap) {
                throw new AiException(AiException::BUDGET, 'ใช้ key กลางขององค์กรครบเพดานเดือนนี้แล้ว — เพิ่ม API Key ของคุณเองได้ที่หน้า "AI ของฉัน"');
            }

            return [(string) $this->config->sharedKey(), 'shared', null];
        }

        $reason = match ($credential?->status) {
            'invalid' => 'API Key ของคุณใช้ไม่ได้ — กรุณาใส่ key ใหม่ที่หน้า "AI ของฉัน"',
            'quota_exceeded' => 'token ของคุณหมดโควต้าแล้ว งานที่ทำไว้ยังอยู่ครบ ใช้โหมดไม่ใช้ AI ต่อได้',
            'revoked' => 'API Key ของคุณถูกผู้ดูแลระบบยกเลิก',
            default => 'ยังไม่ได้เพิ่ม API Key — เพิ่มได้ที่หน้า "AI ของฉัน" หรือใช้โหมดไม่ใช้ AI',
        };

        throw new AiException($credential ? AiException::INVALID_KEY : AiException::NO_KEY, $reason);
    }

    private function sendWithRetry(string $apiKey, AiRequest $request): AiResponse
    {
        $attempt = 0;
        while (true) {
            try {
                return $this->transport->send($apiKey, $request);
            } catch (AiException $e) {
                if ($e->kind !== AiException::TRANSIENT || $attempt >= config('ai.retries')) {
                    throw $e;
                }
                usleep((int) config('ai.retry_delay_ms') * 1000 * (2 ** $attempt));
                $attempt++;
            }
        }
    }

    private function markCredential(?UserAiCredential $credential, AiException $e): void
    {
        if (! $credential) {
            return;
        }
        $status = match ($e->kind) {
            AiException::INVALID_KEY => 'invalid',
            AiException::QUOTA => 'quota_exceeded',
            default => null,
        };
        $credential->forceFill(array_filter(['status' => $status, 'last_error' => mb_substr($e->getMessage(), 0, 250)]))->save();
    }

    private function cached(string $cacheKey): ?AiResponse
    {
        $row = DB::table('ai_response_cache')->where('cache_key', $cacheKey)->where('expires_at', '>', now())->first();
        if (! $row) {
            return null;
        }
        DB::table('ai_response_cache')->where('cache_key', $cacheKey)->increment('hit_count');
        $data = json_decode($row->response, true);

        return new AiResponse($data['text'], 0, 0, $data['model'] ?? '', true, 'none');
    }

    private function log(User $user, ?Dataset $dataset, AiRequest $request, ?AiResponse $response, string $hash, string $source = 'none', ?string $error = null, int $latency = 0): void
    {
        $conversation = AiConversation::create(['user_id' => $user->id, 'dataset_id' => $dataset?->id, 'stage' => $request->stage]);
        $logPayload = (bool) config('ai.log_payload');
        $conversation->messages()->create([
            'role' => 'assistant',
            'payload_hash' => $hash,
            'content' => $logPayload ? json_encode(['request' => $request->messages, 'response' => $response?->text], JSON_UNESCAPED_UNICODE) : null,
            'model' => $response?->model ?? $this->config->model($request->tier),
            'input_tokens' => $response?->inputTokens ?? 0,
            'output_tokens' => $response?->outputTokens ?? 0,
            'key_source' => $response?->fromCache ? 'none' : $source,
            'from_cache' => (bool) $response?->fromCache,
            'latency_ms' => $latency,
            'error' => $error,
            'created_at' => now(),
        ]);
    }
}
