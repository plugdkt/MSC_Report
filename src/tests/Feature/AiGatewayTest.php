<?php

namespace Tests\Feature;

use App\Models\AiUsage;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserAiCredential;
use App\Services\Ai\AiException;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiFake;
use Tests\TestCase;

class AiGatewayTest extends TestCase
{
    use AiFake;
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureAi();
        $this->user = User::factory()->create();
    }

    private function request(): AiRequest
    {
        return new AiRequest('clarify', 'ระบบ', [['role' => 'user', 'content' => 'สวัสดี']]);
    }

    private function gateway(): AiGateway
    {
        return app(AiGateway::class);
    }

    public function test_openai_format_uses_personal_key_and_records_usage(): void
    {
        $this->giveKey($this->user, 'sk-personal-key-9999');
        Http::fake([self::AI_URL => self::chat('{"ok":true}', 1000, 200)]);

        $response = $this->gateway()->complete($this->user, $this->request());

        $this->assertSame('{"ok":true}', $response->text);
        $this->assertSame('personal', $response->keySource);
        Http::assertSent(fn (Request $r) => $r->url() === self::AI_URL
            && $r->hasHeader('Authorization', 'Bearer sk-personal-key-9999')
            && $r['model'] === 'org-model-large'
            && $r['messages'][0]['role'] === 'system'
            && $r['max_tokens'] === 3000);
        $this->assertSame(1200, AiUsage::monthTotal($this->user->id, 'personal'));
        $this->assertNotNull($this->user->aiCredential->fresh()->last_used_at);
        $this->assertSame(1, DB::table('ai_messages')->where('input_tokens', 1000)->count());
        $this->assertNull(DB::table('ai_messages')->value('content'), 'ไม่เก็บ payload เมื่อไม่ได้เปิด AI_LOG_PAYLOAD');
    }

    public function test_anthropic_format(): void
    {
        $this->configureAi('anthropic');
        $this->giveKey($this->user, 'sk-ant-key-0000');
        Http::fake(['https://ai.test/v1/messages' => Http::response([
            'model' => 'org-model-large',
            'content' => [['type' => 'text', 'text' => '{"a":1}']],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 10],
        ])]);

        $response = $this->gateway()->complete($this->user, $this->request());

        $this->assertSame('{"a":1}', $response->text);
        Http::assertSent(fn (Request $r) => $r->hasHeader('x-api-key', 'sk-ant-key-0000') && $r->hasHeader('anthropic-version') && $r['system'] === 'ระบบ');
    }

    public function test_invalid_key_marks_credential_and_is_not_retried(): void
    {
        $this->giveKey($this->user);
        Http::fake([self::AI_URL => Http::response(['error' => 'bad key'], 401)]);

        try {
            $this->gateway()->complete($this->user, $this->request());
            $this->fail('ควร throw');
        } catch (AiException $e) {
            $this->assertSame(AiException::INVALID_KEY, $e->kind);
        }

        Http::assertSentCount(1);
        $this->assertSame('invalid', $this->user->aiCredential->fresh()->status);
        $this->assertFalse($this->gateway()->availability($this->user->fresh())['available']);
    }

    public function test_quota_exceeded_marks_credential(): void
    {
        $this->giveKey($this->user);
        Http::fake([self::AI_URL => Http::response(['error' => ['message' => 'You exceeded your current quota']], 429)]);

        $this->expectExceptionObject(new AiException(AiException::QUOTA, 'token ของคุณหมดโควต้าแล้ว งานที่ทำไว้ยังอยู่ครบ ใช้โหมดไม่ใช้ AI ต่อได้'));
        try {
            $this->gateway()->complete($this->user, $this->request());
        } finally {
            $this->assertSame('quota_exceeded', $this->user->aiCredential->fresh()->status);
        }
    }

    public function test_transient_errors_are_retried(): void
    {
        $this->giveKey($this->user);
        Http::fakeSequence(self::AI_URL)->push('busy', 503)->push('busy', 502)->pushResponse(self::chat('{"x":1}'));

        $this->assertSame('{"x":1}', $this->gateway()->complete($this->user, $this->request())->text);
        Http::assertSentCount(3);
    }

    public function test_personal_budget_blocks_before_calling_ai(): void
    {
        $this->giveKey($this->user)->update(['monthly_token_budget' => 1000]);
        AiUsage::record($this->user->id, 'personal', 900, 50);
        Http::fake();

        try {
            $this->gateway()->complete($this->user, $this->request());
            $this->fail('ควร throw');
        } catch (AiException $e) {
            $this->assertSame(AiException::BUDGET, $e->kind);
        }
        Http::assertNothingSent();
    }

    public function test_shared_key_is_used_when_user_has_no_key_and_capped(): void
    {
        Setting::put('ai.shared_key', 'sk-shared-org', true);
        Setting::put('ai.shared_enabled', 1);
        Setting::put('ai.shared_monthly_cap', 4000);
        Http::fake([self::AI_URL => self::chat('{}', 3000, 500)]);

        $this->assertSame('shared', $this->gateway()->complete($this->user, $this->request())->keySource);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk-shared-org'));
        $this->assertNotSame('sk-shared-org', DB::table('settings')->where('key', 'ai.shared_key')->value('value'), 'key กลางต้องเก็บแบบเข้ารหัส');

        $this->expectException(AiException::class);
        $this->gateway()->complete($this->user, new AiRequest('clarify', 'อื่น', [['role' => 'user', 'content' => 'ขอใหม่']]));
    }

    public function test_without_key_or_configuration(): void
    {
        $this->assertStringContainsString('ยังไม่ได้เพิ่ม API Key', $this->gateway()->availability($this->user)['reason']);

        Setting::put('ai.base_url', null);
        $this->assertStringContainsString('ยังไม่ได้ตั้งค่า AI', $this->gateway()->availability($this->user)['reason']);
    }

    public function test_cached_answers_are_reused_without_calling_ai(): void
    {
        $this->giveKey($this->user);
        Http::fake([self::AI_URL => self::chat('{"cached":true}')]);
        $gateway = $this->gateway();

        $first = $gateway->complete($this->user, $this->request());
        $gateway->storeCache($this->request(), $first);
        $second = $gateway->complete($this->user, $this->request());

        $this->assertTrue($second->fromCache);
        $this->assertSame('{"cached":true}', $second->text);
        Http::assertSentCount(1);
        $this->assertSame(1, (int) DB::table('ai_response_cache')->value('hit_count'));
    }

    public function test_gateway_daily_quota_is_remembered_and_reasoning_overflow_is_explained(): void
    {
        $credential = $this->giveKey($this->user);
        Http::fakeSequence(self::AI_URL)
            ->push(['model' => 'org-model-large', 'choices' => [['message' => ['content' => '{}'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
                'model_quota' => ['daily_quota_tokens' => 150000, 'daily_usage_tokens' => 512, 'daily_remaining_tokens' => 149488]])
            ->push(['model' => 'org-model-large', 'choices' => [['message' => ['content' => null, 'reasoning' => 'คิด…'], 'finish_reason' => 'length']], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3000]]);

        $this->gateway()->complete($this->user, $this->request());
        $this->assertSame(149488, $credential->fresh()->quota['org-model-large']['remaining']);

        $this->expectExceptionMessage('ใช้ token คิดจนหมดก่อนตอบ');
        $this->gateway()->complete($this->user, new AiRequest('clarify', 'อื่น', [['role' => 'user', 'content' => 'ใหม่']]));
    }

    public function test_pasted_key_is_cleaned_and_errors_show_gateway_reason(): void
    {
        $this->assertSame('sk_abc123', UserAiCredential::clean(" Bearer sk_abc\n123 "));
        $this->assertSame('sk_abc123', UserAiCredential::clean('"sk_abc123"'));

        $e = AiException::fromStatus(403, '{"error":"Model not allowed for this key: sk_secretsecret"}', 'big-model');
        $this->assertStringContainsString('ไม่มีสิทธิ์ใช้โมเดล big-model', $e->getMessage());
        $this->assertStringNotContainsString('sk_secretsecret', $e->getMessage());
    }

    public function test_stored_key_is_encrypted_and_hidden(): void
    {
        $credential = $this->giveKey($this->user, 'sk-secret-abcdefgh');

        $this->assertStringNotContainsString('sk-secret', DB::table('user_ai_credentials')->value('encrypted_key'));
        $this->assertArrayNotHasKey('encrypted_key', $credential->toArray());
        $this->assertSame('efgh', $credential->key_last4);
        $this->assertSame('sk-secret-abcdefgh', $credential->plainKey());
    }
}
