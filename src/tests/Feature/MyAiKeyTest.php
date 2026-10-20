<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Models\UserAiCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\AiFake;
use Tests\TestCase;

class MyAiKeyTest extends TestCase
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

    public function test_key_is_saved_only_after_successful_test(): void
    {
        Http::fake([self::AI_URL => Http::sequence()->push(['error' => 'no'], 401)->pushResponse(self::chat('ok', 5, 1))]);

        $this->actingAs($this->user)->post(route('me.ai.store'), ['api_key' => 'sk-wrong-key-111'])->assertSessionHas('error');
        $this->assertNull($this->user->aiCredential()->first());

        $this->actingAs($this->user)->post(route('me.ai.store'), ['api_key' => 'sk-right-key-2222'])->assertSessionHas('status');
        $credential = $this->user->aiCredential()->first();
        $this->assertSame('2222', $credential->key_last4);
        $this->assertSame('active', $credential->status);
        $this->assertNotNull($credential->last_verified_at);
    }

    public function test_page_never_shows_full_key(): void
    {
        $this->giveKey($this->user, 'sk-very-secret-777788889999');

        $this->actingAs($this->user)->get(route('me.ai'))->assertOk()
            ->assertSee('••••9999')
            ->assertDontSee('sk-very-secret');
    }

    public function test_user_sets_budget_and_deletes_key(): void
    {
        $this->giveKey($this->user);

        $this->actingAs($this->user)->put(route('me.ai.budget'), ['monthly_token_budget' => 50000])->assertSessionHas('status');
        $this->assertSame(50000, $this->user->aiCredential()->first()->monthly_token_budget);

        $this->actingAs($this->user)->delete(route('me.ai.destroy'))->assertSessionHas('status');
        $this->assertSame(0, UserAiCredential::count());
    }

    public function test_admin_configures_ai_and_revokes_user_key(): void
    {
        $admin = User::factory()->admin()->create();
        $credential = $this->giveKey($this->user);

        $this->actingAs($admin)->put(route('admin.ai.update'), [
            'format' => 'anthropic', 'base_url' => 'https://ai.example.ac.th/v1', 'auth_style' => 'x-api-key',
            'model_smart' => 'big', 'model_fast' => '', 'json_mode' => '1', 'timeout' => 90,
            'shared_enabled' => '1', 'shared_key' => 'sk-org-shared', 'shared_monthly_cap' => 30000,
        ])->assertSessionHas('status');

        $this->assertSame('anthropic', Setting::get('ai.format'));
        $this->assertSame('https://ai.example.ac.th/v1', Setting::get('ai.base_url'));
        $this->assertSame('sk-org-shared', Setting::get('ai.shared_key'));

        $this->actingAs($admin)->get(route('admin.ai.edit'))->assertOk()->assertDontSee('sk-org-shared')->assertSee($this->user->name);
        $this->actingAs($admin)->post(route('admin.ai.revoke', $credential))->assertSessionHas('status');
        $this->assertSame('revoked', $credential->fresh()->status);
    }

    public function test_staff_cannot_open_admin_ai_settings(): void
    {
        $this->actingAs($this->user)->get(route('admin.ai.edit'))->assertForbidden();
        $this->actingAs($this->user)->put(route('admin.ai.update'), ['format' => 'openai'])->assertForbidden();
    }
}
