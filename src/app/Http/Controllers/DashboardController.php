<?php

namespace App\Http\Controllers;

use App\Models\Dashboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * แสดง Dashboard ที่สร้างแล้ว (ต้นแบบ M2 — การแก้ไข/ลากวาง/ส่งออกอยู่ใน M3–M4)
 */
class DashboardController extends Controller
{
    public function show(Request $request, Dashboard $dashboard): View
    {
        abort_unless($dashboard->user_id === $request->user()->id || $request->user()->isAdmin(), 403);

        $version = $dashboard->currentVersion ?? abort(404);

        return view('dashboards.show', [
            'dashboard' => $dashboard,
            'version' => $version,
            'spec' => $version->spec,
            'computed' => $version->computed_data,
            'dataset' => $dashboard->dataset,
        ]);
    }
}
