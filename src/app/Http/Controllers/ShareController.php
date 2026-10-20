<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use App\Models\Share;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * แชร์ Dashboard ให้ผู้ใช้ในระบบดูอย่างเดียว (spec FR-8.1)
 */
class ShareController extends Controller
{
    public function store(Request $request, Dashboard $dashboard): RedirectResponse
    {
        Gate::authorize('share', $dashboard);
        $data = $request->validate(['person' => ['required', 'string', 'max:255']], ['person.required' => 'กรุณาเลือกผู้รับ']);

        // รับ "username — ชื่อ" จากช่องค้นหา หรือพิมพ์ username ตรง ๆ
        $username = mb_strtolower(trim(preg_split('/\s+[—-]\s+/u', $data['person'])[0]));
        $target = User::where('username', $username)->where('is_active', true)->first();

        if (! $target) {
            return back()->with('error', 'ไม่พบผู้ใช้ "'.$data['person'].'" — ผู้รับต้องเคยเข้าสู่ระบบ MSC Report แล้ว');
        }
        if ($target->is($request->user())) {
            return back()->with('error', 'ไม่ต้องแชร์ให้ตัวเอง');
        }

        $share = Share::firstOrCreate(['dashboard_id' => $dashboard->id, 'user_id' => $target->id], ['created_by' => $request->user()->id]);
        if ($share->wasRecentlyCreated) {
            Audit::log('share.added', $dashboard, ['with' => $target->username], summary: $dashboard->title);
        }

        return back()->with('status', 'แชร์ให้ '.$target->name.' แล้ว (ดูและส่งออกได้ แก้ไขไม่ได้)');
    }

    public function destroy(Dashboard $dashboard, Share $share): RedirectResponse
    {
        Gate::authorize('share', $dashboard);
        abort_unless($share->dashboard_id === $dashboard->id, 404);
        $share->delete();
        Audit::log('share.removed', $dashboard, ['with' => $share->user->username], summary: $dashboard->title);

        return back()->with('status', 'ยกเลิกการแชร์แล้ว');
    }

    /**
     * ค้นหาผู้รับสำหรับช่อง autocomplete — เฉพาะผู้ใช้ที่ล็อกอินแล้ว คืนข้อมูลน้อยที่สุด
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }
        $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

        return response()->json(User::where('is_active', true)->whereKeyNot($request->user()->id)
            ->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('username', 'like', $like))
            ->orderBy('name')->limit(8)->get(['username', 'name', 'pos_name', 'div_name'])
            ->map(fn ($u) => ['value' => $u->username.' — '.$u->name, 'detail' => trim(($u->pos_name ?? '').' '.($u->div_name ?? ''))]));
    }
}
