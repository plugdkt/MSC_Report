<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiUsage;
use App\Models\AuditLog;
use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\Export;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class OverviewController extends Controller
{
    public function index(): View
    {
        $month = now()->format('Y-m');
        $tokens = AiUsage::where('period', $month)->selectRaw('coalesce(sum(input_tokens + output_tokens), 0) as t, coalesce(sum(request_count), 0) as r')->first();

        return view('admin.overview', [
            'stats' => [
                'ผู้ใช้ที่ใช้งานอยู่' => User::where('is_active', true)->count(),
                'ใช้งานใน 30 วัน' => User::where('last_login_at', '>=', now()->subDays(30))->count(),
                'ข้อมูลที่นำเข้า' => Dataset::count(),
                'รายงาน' => Dashboard::count(),
                'ส่งออกใน 30 วัน' => Export::where('created_at', '>=', now()->subDays(30))->count(),
                'ส่งออกไม่สำเร็จ (30 วัน)' => Export::where('created_at', '>=', now()->subDays(30))->where('status', 'failed')->count(),
                'token AI เดือนนี้' => (int) $tokens->t,
                'เรียก AI เดือนนี้ (ครั้ง)' => (int) $tokens->r,
            ],
            'queue' => [
                'pending' => DB::table('jobs')->count(),
                'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            ],
            'recent' => AuditLog::with('user')->latest('id')->limit(10)->get(),
            'topUsers' => AiUsage::where('period', $month)->join('users', 'users.id', '=', 'ai_usages.user_id')
                ->selectRaw('users.name, users.username, sum(input_tokens + output_tokens) as tokens')->groupBy('users.id', 'users.name', 'users.username')
                ->orderByDesc('tokens')->limit(5)->get(),
        ]);
    }
}
