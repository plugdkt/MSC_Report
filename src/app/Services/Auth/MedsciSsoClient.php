<?php

namespace App\Services\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * เชื่อมต่อระบบยืนยันตัวตนกลาง MEDSCI ACC แบบ SSO Redirect (sso_integration_guide.md วิธีที่ 1)
 */
class MedsciSsoClient
{
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self(config('services.medsci_sso'));
    }

    public function isConfigured(): bool
    {
        return filled($this->config['client_id'] ?? null) && filled($this->config['client_secret'] ?? null);
    }

    public function loginUrl(string $redirectUri, string $state): string
    {
        return $this->config['base_url'].'/sso/login.php?'.http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ]);
    }

    public function logoutUrl(string $redirectUri): string
    {
        return $this->config['base_url'].'/sso/logout.php?'.http_build_query([
            'redirect_uri' => $redirectUri,
            'client_id' => $this->config['client_id'],
        ]);
    }

    /**
     * ส่งโทเคน (อายุ 2 นาที ใช้ได้ครั้งเดียว) ไปตรวจที่ระบบกลาง แล้วคืนโปรไฟล์ผู้ใช้
     *
     * @return array{user_id:int|string|null, username:string, name:string, pos_name:?string, div_name:?string, email:?string}
     *
     * @throws SsoException
     */
    public function verify(string $token): array
    {
        try {
            $response = Http::asForm()
                ->timeout($this->config['timeout'] ?? 15)
                ->withOptions(['verify' => $this->config['verify_ssl'] ?? true])
                ->post($this->config['base_url'].'/api/verify.php', [
                    'token' => $token,
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                ]);
        } catch (ConnectionException $e) {
            throw new SsoException('ไม่สามารถเชื่อมต่อระบบยืนยันตัวตนกลางได้ กรุณาลองใหม่อีกครั้ง', previous: $e);
        }

        $data = $response->json();

        if (! is_array($data) || ($data['status'] ?? null) !== 'success' || empty($data['user']['username'])) {
            throw new SsoException($data['message'] ?? 'โทเคนยืนยันสิทธิ์ไม่ถูกต้อง หมดอายุ หรือถูกใช้ไปแล้ว');
        }

        $u = $data['user'];

        return [
            'user_id' => $u['user_id'] ?? null,
            'username' => strtolower(trim($u['username'])),
            'name' => trim($u['name'] ?? $u['username']),
            'pos_name' => $u['pos_name'] ?? null,
            'div_name' => $u['div_name'] ?? null,
            'email' => $u['email'] ?? null,
        ];
    }
}
