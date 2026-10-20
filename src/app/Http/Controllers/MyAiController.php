<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiUsage;
use App\Models\UserAiCredential;
use App\Services\Ai\AiConfig;
use App\Services\Ai\AiException;
use App\Services\Ai\AiGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * หน้า "AI ของฉัน": เพิ่ม/ทดสอบ/ลบ API Key ส่วนตัว และดูการใช้ token (spec FR-9.4–9.6)
 */
class MyAiController extends Controller
{
    public function show(Request $request, AiConfig $config, AiGateway $gateway): View
    {
        $user = $request->user();

        return view('me.ai', [
            'credential' => $user->aiCredential,
            'configured' => $config->isConfigured(),
            'availability' => $gateway->availability($user),
            'monthPersonal' => AiUsage::monthTotal($user->id, 'personal'),
            'monthShared' => AiUsage::monthTotal($user->id, 'shared'),
            'history' => AiUsage::where('user_id', $user->id)->orderByDesc('period')->limit(12)->get(),
            'byStage' => AiConversation::where('ai_conversations.user_id', $user->id)
                ->join('ai_messages', 'ai_messages.ai_conversation_id', '=', 'ai_conversations.id')
                ->where('ai_conversations.created_at', '>=', now()->startOfMonth())
                ->selectRaw('ai_conversations.stage, sum(ai_messages.input_tokens + ai_messages.output_tokens) as tokens, sum(ai_messages.from_cache) as cached, count(*) as calls')
                ->groupBy('ai_conversations.stage')->get(),
        ]);
    }

    /**
     * ทดสอบ key แล้วบันทึกเมื่อผ่านเท่านั้น
     */
    public function store(Request $request, AiGateway $gateway): RedirectResponse
    {
        $data = $request->validate(['api_key' => ['required', 'string', 'min:8', 'max:500']], [
            'api_key.required' => 'กรุณาวาง API Key',
            'api_key.min' => 'API Key สั้นเกินไป',
        ]);
        $key = UserAiCredential::clean($data['api_key']);

        try {
            $test = $gateway->testKey($key);
        } catch (AiException $e) {
            return back()->with('error', 'ทดสอบ key ไม่ผ่าน จึงยังไม่บันทึก: '.$e->getMessage());
        }

        $credential = UserAiCredential::updateOrCreate(['user_id' => $request->user()->id], UserAiCredential::attributesFor($key) + [
            'status' => 'active',
            'last_verified_at' => now(),
            'last_error' => null,
        ]);
        $credential->rememberQuota($test->model, $test->quota);

        return back()->with('status', 'บันทึก API Key แล้ว (แสดงเฉพาะ 4 ตัวท้าย) — ทดสอบกับโมเดล '.$test->model.' ผ่าน');
    }

    public function updateBudget(Request $request): RedirectResponse
    {
        $data = $request->validate(['monthly_token_budget' => ['nullable', 'integer', 'min:1000', 'max:100000000']]);
        $credential = $request->user()->aiCredential ?? abort(404);
        $credential->update(['monthly_token_budget' => $data['monthly_token_budget'] ?? null]);

        return back()->with('status', 'บันทึกงบ token ต่อเดือนแล้ว');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->user()->aiCredential?->delete();

        return back()->with('status', 'ลบ API Key แล้ว');
    }
}
