<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\MedsciSsoClient;
use App\Services\Auth\SsoException;
use App\Services\Auth\UserProvisioner;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SsoController extends Controller
{
    public function __construct(private readonly MedsciSsoClient $sso) {}

    /**
     * หน้าเข้าสู่ระบบ
     */
    public function showLogin(): View
    {
        return view('auth.login', [
            'ssoConfigured' => $this->sso->isConfigured(),
            'devLogin' => $this->devLoginAllowed(),
            'devUsername' => config('app.dev_login.username'),
        ]);
    }

    /**
     * ส่งผู้ใช้ไปหน้าล็อกอินกลาง พร้อม state ป้องกัน Login CSRF
     */
    public function redirect(Request $request): RedirectResponse
    {
        if (! $this->sso->isConfigured()) {
            return redirect()->route('login')->with('error', 'ยังไม่ได้ตั้งค่า Client ID / Client Secret ของระบบยืนยันตัวตนกลาง');
        }

        $state = Str::random(40);
        $request->session()->put('sso_state', $state);

        return redirect()->away($this->sso->loginUrl(route('sso.callback'), $state));
    }

    /**
     * รับโทเคนจากระบบกลาง ตรวจ state แล้วส่งโทเคนไปตรวจเบื้องหลัง
     */
    public function callback(Request $request, UserProvisioner $provisioner): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $savedState = (string) $request->session()->pull('sso_state', '');
        $token = trim((string) $request->query('token', ''));

        if ($state === '' || $savedState === '' || ! hash_equals($savedState, $state)) {
            Audit::log('auth.login_failed', meta: ['reason' => 'state ไม่ตรง'], summary: 'สถานะคำขอไม่ถูกต้อง');

            return $this->fail('คำขอเข้าสู่ระบบไม่ถูกต้องหรือหมดอายุ กรุณาเริ่มเข้าสู่ระบบใหม่');
        }

        if ($token === '') {
            return $this->fail('ไม่พบโทเคนยืนยันตัวตนจากระบบกลาง');
        }

        try {
            $profile = $this->sso->verify($token);
        } catch (SsoException $e) {
            Log::warning('SSO verify failed', ['message' => $e->getMessage()]);
            Audit::log('auth.login_failed', meta: ['reason' => 'ระบบกลางปฏิเสธโทเคน'], summary: $e->getMessage());

            return $this->fail($e->getMessage());
        }

        return $this->loginAs($request, $provisioner->provision($profile));
    }

    /**
     * เข้าสู่ระบบโดยไม่ผ่าน SSO — เฉพาะเครื่อง dev (APP_ENV=local และ DEV_LOGIN_ENABLED=true)
     */
    public function devLogin(Request $request, UserProvisioner $provisioner): RedirectResponse
    {
        abort_unless($this->devLoginAllowed(), 404);

        $username = strtolower((string) config('app.dev_login.username'));
        $user = User::where('username', $username)->first()
            ?? $provisioner->provision([
                'user_id' => null,
                'username' => $username,
                'name' => $username,
                'pos_name' => null,
                'div_name' => null,
                'email' => null,
            ]);

        if ($provisioner->isConfiguredAdmin($user->username) && ! $user->isAdmin()) {
            $user->update(['role' => 'admin']);
        }

        return $this->loginAs($request, $user);
    }

    /**
     * ออกจากระบบ และล้าง session ที่ระบบกลาง (Single Logout)
     */
    public function logout(Request $request): RedirectResponse
    {
        Audit::log('auth.logout');
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if (! $this->sso->isConfigured()) {
            return redirect()->route('login');
        }

        return redirect()->away($this->sso->logoutUrl(route('login')));
    }

    private function loginAs(Request $request, User $user): RedirectResponse
    {
        if (! $user->is_active) {
            Audit::log('auth.login_failed', meta: ['reason' => 'บัญชีถูกระงับ'], user: $user, summary: $user->username);

            return $this->fail('บัญชีของคุณถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ');
        }

        Auth::login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        Audit::log('auth.login', $user, user: $user);

        // redirect ออกจากหน้า callback ทันที เพื่อไม่ให้ token ค้างใน URL/History
        return redirect()->intended(route('home'));
    }

    private function fail(string $message): RedirectResponse
    {
        return redirect()->route('login')->with('error', $message);
    }

    private function devLoginAllowed(): bool
    {
        return app()->environment('local')
            && config('app.dev_login.enabled')
            && filled(config('app.dev_login.username'));
    }
}
