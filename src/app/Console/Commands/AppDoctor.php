<?php

namespace App\Console\Commands;

use App\Services\Ai\AiConfig;
use App\Services\Auth\MedsciSsoClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ตรวจความพร้อมของสภาพแวดล้อมก่อนขึ้น production (spec §10 security checklist)
 */
#[Signature('app:doctor')]
#[Description('ตรวจการตั้งค่า/ความปลอดภัย/ความพร้อมของระบบ')]
class AppDoctor extends Command
{
    private int $fails = 0;

    public function handle(AiConfig $ai, MedsciSsoClient $sso): int
    {
        $prod = app()->isProduction();
        $this->line('สภาพแวดล้อม: <info>'.app()->environment().'</info> (ข้อที่เข้มงวดจะนับเป็นข้อผิดพลาดเฉพาะ production)');

        $this->check('APP_DEBUG ปิด', ! config('app.debug'), $prod);
        $this->check('APP_KEY ตั้งแล้ว', filled(config('app.key')));
        $this->check('ปิดปุ่ม dev-login', ! app()->environment('local') || ! config('app.dev_login.enabled'), $prod);
        $this->check('APP_URL เป็น https', str_starts_with((string) config('app.url'), 'https://'), $prod);
        $this->check('SESSION_SECURE_COOKIE เปิด', (bool) config('session.secure'), $prod);
        $this->check('ตั้งค่า SSO (Client ID/Secret)', $sso->isConfigured());
        $this->check('ตั้งค่า AI องค์กร (Base URL)', filled($ai->get('base_url')), false);
        $this->check('มีผู้ดูแลระบบอย่างน้อย 1 คน', DB::table('users')->where('role', 'admin')->exists(), false);

        try {
            DB::select('select 1');
            $this->check('เชื่อมต่อฐานข้อมูล', true);
        } catch (\Throwable) {
            $this->check('เชื่อมต่อฐานข้อมูล', false);
        }

        $this->check('เขียน storage ได้', is_writable(storage_path('app')) && is_writable(storage_path('logs')));
        $this->check('ไฟล์อัปโหลดไม่อยู่ใน public', ! str_contains(Storage::disk('local')->path(''), DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR));
        $chrome = (string) config('export.chrome_path');
        $this->check('พบ Chrome สำหรับส่งออก', $chrome !== '' && is_file($chrome), false, $chrome);
        $this->check('ฟอนต์ Sarabun ในเครื่อง', count(glob(public_path('fonts/*')) ?: []) > 0);
        $this->check('ECharts ในเครื่อง', count(glob(public_path('vendor/echarts*')) ?: []) > 0);
        foreach (['zip', 'mbstring', 'gd', 'sockets', 'xml'] as $ext) {
            $this->check("PHP ext {$ext}", extension_loaded($ext));
        }
        $this->check('upload_max_filesize ≥ IMPORT_MAX_KB', $this->bytes((string) ini_get('upload_max_filesize')) >= config('import.max_kb') * 1024, false, (string) ini_get('upload_max_filesize'));
        $this->check('คิวไม่ใช่ sync', config('queue.default') !== 'sync');
        $this->check('หน้า render รับเฉพาะ IP ภายใน', (bool) config('export.render_local_only'), false);

        $this->newLine();
        $this->line($this->fails === 0 ? '<info>พร้อมใช้งาน</info>' : "<error>พบ {$this->fails} รายการที่ต้องแก้</error>");

        return $this->fails === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  bool  $strict  true = ไม่ผ่านแล้วนับเป็นความล้มเหลว, false = เตือนเฉยๆ
     */
    private function check(string $label, bool $ok, bool $strict = true, string $detail = ''): void
    {
        $suffix = $detail !== '' ? " ({$detail})" : '';
        if ($ok) {
            $this->line('  <info>✓</info> '.$label.$suffix);
        } elseif ($strict) {
            $this->fails++;
            $this->line('  <error>✗</error> '.$label.$suffix);
        } else {
            $this->line('  <comment>!</comment> '.$label.$suffix);
        }
    }

    private function bytes(string $v): int
    {
        $n = (int) $v;

        return match (strtolower(substr(trim($v), -1))) {
            'g' => $n * 1073741824,
            'm' => $n * 1048576,
            'k' => $n * 1024,
            default => $n,
        };
    }
}
