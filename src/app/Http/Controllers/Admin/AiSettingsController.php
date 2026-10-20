<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\Setting;
use App\Models\UserAiCredential;
use App\Services\Ai\AiConfig;
use App\Services\Ai\AiException;
use App\Services\Ai\AiGateway;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * ตั้งค่า AI ขององค์กร (spec FR-10): Base URL / รูปแบบ API / โมเดล / key กลาง — ผู้ใช้ทั่วไปแก้ Base URL ไม่ได้
 */
class AiSettingsController extends Controller
{
    public function edit(AiConfig $config): View
    {
        return view('admin.ai', [
            'config' => $config,
            'sharedSet' => filled(Setting::get('ai.shared_key')),
            'credentials' => UserAiCredential::with('user')->latest('updated_at')->get(),
            'usage' => AiUsage::where('period', now()->format('Y-m'))
                ->selectRaw('key_source, sum(input_tokens + output_tokens) as tokens, sum(request_count) as requests')
                ->groupBy('key_source')->get()->keyBy('key_source'),
            'cacheSaved' => (int) DB::table('ai_response_cache')->selectRaw('coalesce(sum(tokens_saved * hit_count), 0) as s')->value('s'),
            'models' => $this->availableModels($config),
        ]);
    }

    /**
     * รายชื่อโมเดลจาก {base_url}/models (ถ้า gateway เปิดให้ดู) — ใช้เป็นตัวเลือกในช่องโมเดล
     *
     * @return list<string>
     */
    private function availableModels(AiConfig $config): array
    {
        $base = $config->get('base_url');
        if (! $base) {
            return [];
        }

        return Cache::remember('ai.models.'.md5($base), now()->addMinutes(10), function () use ($base) {
            try {
                return collect(Http::timeout(8)->acceptJson()->get($base.'/models')->json('data', []))
                    ->pluck('id')->filter()->values()->all();
            } catch (\Throwable) {
                return [];
            }
        });
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'format' => ['required', Rule::in(['openai', 'anthropic'])],
            'base_url' => ['nullable', 'url:https,http', 'max:255'],
            'auth_style' => ['required', Rule::in(['bearer', 'x-api-key', 'api-key'])],
            'model_smart' => ['nullable', 'string', 'max:100'],
            'model_fast' => ['nullable', 'string', 'max:100'],
            'json_mode' => ['nullable', 'boolean'],
            'timeout' => ['required', 'integer', 'min:5', 'max:300'],
            'shared_enabled' => ['nullable', 'boolean'],
            'shared_key' => ['nullable', 'string', 'max:500'],
            'shared_key_clear' => ['nullable', 'boolean'],
            'shared_monthly_cap' => ['required', 'integer', 'min:0', 'max:100000000'],
        ]);

        foreach (AiConfig::KEYS as $key) {
            Setting::put('ai.'.$key, $key === 'json_mode' ? (int) (bool) ($data[$key] ?? false) : ($data[$key] ?? null));
        }
        Setting::put('ai.shared_enabled', (int) (bool) ($data['shared_enabled'] ?? false));
        Setting::put('ai.shared_monthly_cap', $data['shared_monthly_cap']);
        if (! empty($data['shared_key_clear'])) {
            Setting::put('ai.shared_key', null, true);
        } elseif (filled($data['shared_key'] ?? null)) {
            Setting::put('ai.shared_key', trim($data['shared_key']), true);
        }

        Audit::log('ai.settings', meta: ['base_url' => $data['base_url'] ?? null, 'format' => $data['format'], 'model' => $data['model_smart'] ?? null, 'shared_enabled' => (bool) ($data['shared_enabled'] ?? false)], summary: 'ตั้งค่า AI องค์กร');

        return back()->with('status', 'บันทึกการตั้งค่า AI แล้ว');
    }

    /**
     * ทดสอบการเชื่อมต่อด้วย key กลาง (หรือ key ส่วนตัวของ Admin)
     */
    public function test(Request $request, AiGateway $gateway): RedirectResponse
    {
        $key = Setting::get('ai.shared_key') ?? $request->user()->aiCredential?->plainKey();
        if (! $key) {
            return back()->with('error', 'ยังไม่มี key สำหรับทดสอบ — ใส่ key กลาง หรือเพิ่ม key ของคุณที่หน้า "AI ของฉัน"');
        }

        try {
            $response = $gateway->testKey($key);
        } catch (AiException $e) {
            return back()->with('error', 'ทดสอบไม่ผ่าน: '.$e->getMessage());
        }

        return back()->with('status', 'เชื่อมต่อสำเร็จ — โมเดล '.$response->model.' ตอบว่า "'.mb_strimwidth(trim($response->text), 0, 40, '…').'"');
    }

    public function revoke(UserAiCredential $credential): RedirectResponse
    {
        $credential->update(['status' => 'revoked']);
        Audit::log('ai.key_revoked', summary: $credential->user->username);

        return back()->with('status', 'ยกเลิก key ของ '.$credential->user->name.' แล้ว');
    }
}
