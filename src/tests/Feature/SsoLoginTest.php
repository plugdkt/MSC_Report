<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SsoLoginTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY_URL = 'https://sso.test/msc_acc/api/verify.php';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.medsci_sso.base_url' => 'https://sso.test/msc_acc',
            'services.medsci_sso.client_id' => 'msc_report',
            'services.medsci_sso.client_secret' => 'secret-for-tests',
            'app.admin_usernames' => ['wittaya.su'],
            'app.dev_login.enabled' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeVerifySuccess(array $overrides = []): void
    {
        Http::fake([
            self::VERIFY_URL => Http::response([
                'status' => 'success',
                'message' => 'Token is valid',
                'user' => array_merge([
                    'user_id' => 1,
                    'username' => 'wittaya.su',
                    'name' => 'นายวิทยา สุนสะดี',
                    'pos_name' => 'นักวิชาการคอมพิวเตอร์',
                    'div_name' => 'สำนักงานธุรการ',
                    'email' => 'wittaya.su@up.ac.th',
                ], $overrides),
            ]),
        ]);
    }

    public function test_redirect_sends_user_to_central_login_with_state(): void
    {
        $response = $this->get(route('sso.redirect'));

        $state = session('sso_state');
        $this->assertNotEmpty($state);
        $response->assertRedirect('https://sso.test/msc_acc/sso/login.php?'.http_build_query([
            'client_id' => 'msc_report',
            'redirect_uri' => route('sso.callback'),
            'state' => $state,
        ]));
    }

    public function test_callback_rejects_mismatched_state(): void
    {
        Http::fake();

        $this->withSession(['sso_state' => 'expected'])
            ->get(route('sso.callback', ['token' => 'abc', 'state' => 'attacker']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_callback_creates_admin_for_configured_username(): void
    {
        $this->fakeVerifySuccess();

        $this->withSession(['sso_state' => 'abc123'])
            ->get(route('sso.callback', ['token' => 'jwt-token', 'state' => 'abc123']))
            ->assertRedirect(route('home'));

        $user = User::where('username', 'wittaya.su')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('admin', $user->role);
        $this->assertSame('นักวิชาการคอมพิวเตอร์', $user->pos_name);

        Http::assertSent(fn (Request $r) => $r->url() === self::VERIFY_URL
            && $r['token'] === 'jwt-token'
            && $r['client_id'] === 'msc_report'
            && $r['client_secret'] === 'secret-for-tests');
    }

    public function test_other_users_get_staff_role_and_keep_role_on_next_login(): void
    {
        $this->fakeVerifySuccess(['user_id' => 7, 'username' => 'somjai.ja', 'name' => 'นางสาวสมใจ ใจดี']);

        $this->withSession(['sso_state' => 's1'])->get(route('sso.callback', ['token' => 't1', 'state' => 's1']));
        $user = User::where('username', 'somjai.ja')->firstOrFail();
        $this->assertSame('staff', $user->role);

        $user->update(['role' => 'executive']);
        auth()->logout();

        $this->withSession(['sso_state' => 's2'])->get(route('sso.callback', ['token' => 't2', 'state' => 's2']));
        $this->assertSame('executive', $user->fresh()->role);
    }

    public function test_failed_verification_shows_message_from_central_system(): void
    {
        Http::fake([self::VERIFY_URL => Http::response(['status' => 'failed', 'message' => 'Token is invalid or expired'], 401)]);

        $this->withSession(['sso_state' => 'abc'])
            ->get(route('sso.callback', ['token' => 'old', 'state' => 'abc']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error', 'Token is invalid or expired');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        User::factory()->inactive()->create(['username' => 'somjai.ja']);
        $this->fakeVerifySuccess(['username' => 'somjai.ja']);

        $this->withSession(['sso_state' => 'abc'])
            ->get(route('sso.callback', ['token' => 't', 'state' => 'abc']))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_dev_login_is_unavailable_outside_local_environment(): void
    {
        config(['app.dev_login.enabled' => true, 'app.dev_login.username' => 'wittaya.su']);

        $this->post(route('dev.login'))->assertNotFound();
        $this->assertGuest();
    }

    public function test_dev_login_in_local_environment_logs_in_configured_admin(): void
    {
        config(['app.dev_login.enabled' => true, 'app.dev_login.username' => 'wittaya.su']);
        $this->app['env'] = 'local';

        $this->withoutMiddleware(PreventRequestForgery::class)
            ->post(route('dev.login'))
            ->assertRedirect(route('home'));

        $this->assertSame('admin', auth()->user()->role);
        $this->assertSame('wittaya.su', auth()->user()->username);
    }

    public function test_logout_redirects_to_central_single_logout(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('logout'))
            ->assertRedirect('https://sso.test/msc_acc/sso/logout.php?'.http_build_query([
                'redirect_uri' => route('login'),
                'client_id' => 'msc_report',
            ]));

        $this->assertGuest();
    }
}
