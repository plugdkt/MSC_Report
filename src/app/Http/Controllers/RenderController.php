<?php

namespace App\Http\Controllers;

use App\Models\DashboardVersion;
use App\Services\Export\ExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * หน้า render สำหรับ Headless Chrome เท่านั้น — เข้าถึงด้วยลิงก์ลงนามอายุ 5 นาที (middleware `signed`)
 * ไม่มี session/ล็อกอิน ข้อมูลที่แสดงเป็นเวอร์ชันที่ผู้ใช้สั่งส่งออก (spec §10 Security)
 */
class RenderController extends Controller
{
    public function report(Request $request, DashboardVersion $version): View
    {
        $portrait = $request->query('o') === 'p';

        return view('render.report', $this->data($version) + [
            'portrait' => $portrait,
            'width' => config('export.content_width')[$portrait ? 'portrait' : 'landscape'],
        ]);
    }

    public function infographic(DashboardVersion $version): View
    {
        $data = $this->data($version);
        abort_unless(! empty($data['spec']['infographic']['enabled']) && isset($data['computed']['_infographic']), 404);

        return view('render.infographic', $data + ['data' => $data['computed']['_infographic'], 'g' => $data['spec']['infographic']]);
    }

    public function dashboard(DashboardVersion $version): View
    {
        return view('render.dashboard', $this->data($version) + ['width' => ExportService::DASHBOARD_WIDTH]);
    }

    public function image(DashboardVersion $version): BinaryFileResponse
    {
        $path = $version->spec['infographic']['image'] ?? null;
        abort_unless($path && str_starts_with($path, 'dashboards/'.$version->dashboard_id.'/') && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, max-age=600']);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(DashboardVersion $version): array
    {
        $version->load('dashboard.dataset', 'dashboard.user');

        return [
            'version' => $version,
            'dashboard' => $version->dashboard,
            'dataset' => $version->dashboard->dataset,
            'spec' => $version->spec,
            'computed' => $version->computed_data,
            'summary' => $version->executive_summary,
        ];
    }
}
