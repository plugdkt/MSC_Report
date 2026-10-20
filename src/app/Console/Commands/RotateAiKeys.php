<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\UserAiCredential;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * เปลี่ยน APP_KEY โดยไม่ทำให้ API Key ที่เข้ารหัสไว้ใช้ไม่ได้ (spec §10)
 * ขั้นตอน: เก็บ APP_KEY เดิมไว้ → ตั้ง APP_KEY ใหม่ใน .env → สั่งคำสั่งนี้ด้วย --old-key
 */
#[Signature('ai-keys:rotate {--old-key= : APP_KEY เดิม (รูปแบบ base64:...)} {--dry-run : ตรวจอย่างเดียว ไม่บันทึก}')]
#[Description('เข้ารหัส API Key ที่เก็บไว้ใหม่ ด้วย APP_KEY ปัจจุบัน (ถอดรหัสด้วย --old-key)')]
class RotateAiKeys extends Command
{
    public function handle(): int
    {
        $old = (string) $this->option('old-key');
        if ($old === '') {
            $this->error('ต้องระบุ --old-key=<APP_KEY เดิม>');

            return self::FAILURE;
        }
        $oldKey = str_starts_with($old, 'base64:') ? base64_decode(substr($old, 7), true) : $old;
        $cipher = config('app.cipher');
        if ($oldKey === false || ! Encrypter::supported($oldKey, $cipher)) {
            $this->error('APP_KEY เดิมไม่ถูกต้อง');

            return self::FAILURE;
        }
        $from = new Encrypter($oldKey, $cipher);
        $to = app('encrypter');
        $dry = (bool) $this->option('dry-run');
        $done = 0;

        try {
            DB::transaction(function () use ($from, $to, $dry, &$done) {
                foreach (UserAiCredential::all() as $credential) {
                    $plain = $from->decryptString($credential->getRawOriginal('encrypted_key'));
                    $credential->forceFill(['encrypted_key' => $to->encryptString($plain)]);
                    $dry || $credential->save();
                    $done++;
                }
                foreach (Setting::where('is_encrypted', true)->whereNotNull('value')->get() as $setting) {
                    $plain = $from->decryptString($setting->getRawOriginal('value'));
                    $setting->forceFill(['value' => $to->encryptString($plain)]);
                    $dry || $setting->save();
                    $done++;
                }
            });
        } catch (\Throwable $e) {
            $this->error('ถอดรหัสไม่สำเร็จ (ไม่มีการเปลี่ยนแปลง): '.$e->getMessage());

            return self::FAILURE;
        }

        Cache::flush();
        $this->info(($dry ? '[ตรวจเท่านั้น] ' : '').'เข้ารหัสใหม่ '.$done.' รายการ');

        return self::SUCCESS;
    }
}
