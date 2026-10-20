<?php

namespace App\Http\Controllers;

use App\Jobs\GeneratePlanJob;
use App\Models\Dashboard;
use App\Models\DashboardVersion;
use App\Models\ReportDraft;
use App\Services\Ai\AiGateway;
use App\Services\CodeLookup;
use App\Services\Report\DashboardService;
use App\Services\Report\InfographicSuggester;
use App\Services\Report\NarrativeService;
use App\Services\Report\SpecValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ดู / แก้ไข / เวอร์ชัน / บทสรุปผู้บริหาร / อินโฟกราฟิก ของ Dashboard (spec FR-4, FR-5, FR-6.5)
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboards) {}

    public function show(Request $request, Dashboard $dashboard, AiGateway $ai): View
    {
        $this->authorizeView($request, $dashboard);
        $version = $dashboard->currentVersion ?? abort(404);

        return view('dashboards.show', [
            'dashboard' => $dashboard,
            'version' => $version,
            'spec' => $version->spec,
            'computed' => $version->computed_data,
            'dataset' => $dashboard->dataset,
            'canEdit' => $this->canEdit($request, $dashboard),
            'themes' => SpecValidator::THEMES,
            'aiAvailable' => $ai->availability($request->user())['available'],
            'exports' => $dashboard->exports()->where('user_id', $request->user()->id)->latest()->limit(5)->get(),
            'isOwner' => $this->canEdit($request, $dashboard),
            'owner' => $dashboard->user,
            'shares' => $this->canEdit($request, $dashboard) ? $dashboard->shares()->with('user')->latest()->get() : collect(),
        ]);
    }

    /**
     * ปรับรายงานด้วยข้อความอิสระ — ทำเป็นงานเบื้องหลัง (ใช้ ReportDraft ชนิด refine) แล้วบันทึกเป็นเวอร์ชันใหม่
     */
    public function refine(Request $request, Dashboard $dashboard, AiGateway $ai): RedirectResponse
    {
        Gate::authorize('update', $dashboard);
        $data = $request->validate(['instruction' => ['required', 'string', 'min:5', 'max:800']], [
            'instruction.required' => 'กรุณาพิมพ์สิ่งที่ต้องการปรับ', 'instruction.min' => 'กรุณาอธิบายให้ชัดขึ้นอีกนิด',
        ]);
        $user = $request->user();

        $availability = $ai->availability($user);
        if (! $availability['available']) {
            return back()->with('error', $availability['reason']);
        }
        if (! $user->ai_consent_at) {
            return back()->with('error', 'ก่อนใช้ AI ต้องรับทราบการส่งข้อมูลสรุปให้ AI — กด "ให้ AI ช่วยสรุป" ที่หน้าข้อมูลของรายงานนี้หนึ่งครั้งเพื่อยอมรับ');
        }

        $draft = ReportDraft::create([
            'user_id' => $user->id, 'dataset_id' => $dashboard->dataset_id, 'dashboard_id' => $dashboard->id,
            'mode' => 'ai', 'kind' => 'refine', 'brief' => trim($data['instruction']), 'status' => 'planning', 'send_samples' => false,
        ]);
        GeneratePlanJob::dispatch($draft->id, []);

        return redirect()->route('drafts.show', $draft);
    }

    public function edit(Request $request, Dashboard $dashboard): View
    {
        $this->authorizeEdit($request, $dashboard);
        $version = $dashboard->currentVersion;

        return view('dashboards.edit', [
            'dashboard' => $dashboard,
            'version' => $version,
            'columns' => $dashboard->dataset->columns()->where('is_hidden', false)->get()->map(fn ($c) => [
                'id' => $c->slug, 'name' => $c->display_name, 'type' => $c->type(), 'numeric' => $c->isNumeric(),
                'groups' => (bool) ($c->code_set_id && app(CodeLookup::class)->sets()->firstWhere('id', $c->code_set_id)?->groupRules->isNotEmpty()),
            ])->values(),
            'themes' => SpecValidator::THEMES,
        ]);
    }

    /**
     * บันทึก spec ที่แก้จากหน้าแก้ไข เป็นเวอร์ชันใหม่ และคืนผลคำนวณใหม่
     */
    public function saveSpec(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeEdit($request, $dashboard);
        $spec = $request->validate(['spec' => ['required', 'array'], 'note' => ['nullable', 'string', 'max:255']]);

        [$version, $errors] = $this->dashboards->saveVersion($dashboard, $spec['spec'], $request->user(), 'user', $spec['note'] ?? 'แก้ไขโดยผู้ใช้');
        if (! $version) {
            return response()->json(['ok' => false, 'errors' => $errors], 422);
        }

        return response()->json(['ok' => true, 'version' => $version->version, 'spec' => $version->spec, 'computed' => $version->computed_data]);
    }

    public function preview(Request $request, Dashboard $dashboard): JsonResponse
    {
        $this->authorizeEdit($request, $dashboard);
        $spec = $request->validate(['spec' => ['required', 'array']])['spec'];
        [$clean, $computed, $errors] = $this->dashboards->preview($dashboard, $spec);

        return $clean
            ? response()->json(['ok' => true, 'spec' => $clean, 'computed' => $computed])
            : response()->json(['ok' => false, 'errors' => $errors], 422);
    }

    public function theme(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeEdit($request, $dashboard);
        $theme = $request->validate(['theme' => ['required', Rule::in(array_keys(SpecValidator::THEMES))]])['theme'];
        $this->dashboards->saveVersion($dashboard, ['theme' => $theme] + $dashboard->currentVersion->spec, $request->user(), 'user', 'เปลี่ยนธีม');

        return back()->with('status', 'เปลี่ยนธีมแล้ว');
    }

    public function summary(Request $request, Dashboard $dashboard, NarrativeService $narratives): RedirectResponse
    {
        $this->authorizeEdit($request, $dashboard);
        $useAi = $request->boolean('ai');
        $notice = $narratives->generate($dashboard->currentVersion, $request->user(), $useAi);

        return back()->with($notice ? 'error' : 'status', $notice ?? ($useAi ? 'AI เขียนบทสรุปใหม่แล้ว (ตรวจตัวเลขผ่านทุกข้อ)' : 'สร้างบทสรุปแบบมาตรฐานแล้ว'));
    }

    public function versions(Request $request, Dashboard $dashboard): View
    {
        $this->authorizeEdit($request, $dashboard);

        return view('dashboards.versions', ['dashboard' => $dashboard, 'versions' => $dashboard->versions()->limit(50)->get()]);
    }

    public function revert(Request $request, Dashboard $dashboard, DashboardVersion $version): RedirectResponse
    {
        $this->authorizeEdit($request, $dashboard);
        abort_unless($version->dashboard_id === $dashboard->id, 404);
        $new = $this->dashboards->revert($dashboard, $version, $request->user());

        return redirect()->route('dashboards.show', $dashboard)->with('status', 'ย้อนกลับเป็นเวอร์ชัน '.$version->version.' แล้ว (บันทึกเป็นเวอร์ชัน '.$new->version.')');
    }

    public function infographic(Request $request, Dashboard $dashboard): View
    {
        $this->authorizeView($request, $dashboard);
        $version = $dashboard->currentVersion;

        return view('dashboards.infographic', [
            'dashboard' => $dashboard,
            'spec' => $version->spec,
            'data' => $version->computed_data['_infographic'] ?? null,
            'summary' => $version->executive_summary,
            'widgets' => $version->spec['widgets'],
            'sizes' => config('export.infographic_sizes'),
            'blockTypes' => SpecValidator::INFOGRAPHIC_BLOCKS,
            'canEdit' => $this->canEdit($request, $dashboard),
            'columns' => $dashboard->dataset->columns()->where('is_hidden', false)->get()->filter->isNumeric()->values(),
        ]);
    }

    public function updateInfographic(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeEdit($request, $dashboard);

        $mode = $request->input('mode', 'parts');
        if (in_array($mode, ['auto', 'story'], true)) {
            return $this->saveStoryInfographic($request, $dashboard, $mode);
        }
        $request->merge(['parts' => array_values(array_filter((array) $request->input('parts', []), fn ($p) => filled($p['column'] ?? null) && filled($p['label'] ?? null)))]);
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'period' => ['nullable', 'string', 'max:80'],
            'unit' => ['nullable', 'string', 'max:150'],
            'total_label' => ['nullable', 'string', 'max:60'],
            'parts' => ['required', 'array', 'min:2', 'max:4'],
            'parts.*.label' => ['required', 'string', 'max:60'],
            'parts.*.column' => ['required', 'string'],
            'parts.*.color' => ['required', Rule::in(['green', 'red', 'blue', 'orange', 'navy', 'gray'])],
        ]);

        $spec = $dashboard->currentVersion->spec;
        $spec['infographic'] = [
            'enabled' => true,
            'title' => $data['title'] ?? null,
            'period' => $data['period'] ?? null,
            'unit' => $data['unit'] ?? null,
            'total_label' => $data['total_label'] ?? 'ทั้งหมด',
            'image' => $spec['infographic']['image'] ?? null,
            'parts' => array_map(fn ($p) => ['label' => $p['label'], 'columns' => [$p['column']], 'color' => $p['color']], array_values($data['parts'])),
        ];

        [$version, $errors] = $this->dashboards->saveVersion($dashboard, $spec, $request->user(), 'user', 'แก้ไขอินโฟกราฟิก');

        return $version
            ? back()->with('status', 'บันทึกอินโฟกราฟิกแล้ว')
            : back()->with('error', implode(' · ', $errors));
    }

    /**
     * อินโฟกราฟิกแบบเล่าเรื่อง: auto = ให้ระบบจัดจาก widget ใหม่, story = ตามที่ผู้ใช้เลือกบล็อก/ขนาด
     */
    private function saveStoryInfographic(Request $request, Dashboard $dashboard, string $mode): RedirectResponse
    {
        $spec = $dashboard->currentVersion->spec;
        $suggester = app(InfographicSuggester::class);

        if ($mode === 'auto') {
            $g = $suggester->story($dashboard->dataset, $spec, $request->user()->div_name);
            $g['image'] = $spec['infographic']['image'] ?? null;
        } else {
            $data = $request->validate([
                'size' => ['required', Rule::in(array_keys(config('export.infographic_sizes')))],
                'title' => ['nullable', 'string', 'max:200'],
                'subtitle' => ['nullable', 'string', 'max:160'],
                'blocks' => ['nullable', 'array', 'max:12'],
                'blocks.*.type' => ['nullable', Rule::in(array_keys(SpecValidator::INFOGRAPHIC_BLOCKS))],
                'blocks.*.ref' => ['nullable', 'string', 'max:20'],
                'blocks.*.title' => ['nullable', 'string', 'max:80'],
                'blocks.*.text' => ['nullable', 'string', 'max:600'],
            ]);
            $kpis = collect($spec['widgets'])->where('type', 'kpi')->take(4)->pluck('id')->all();
            $blocks = [];
            foreach ((array) ($data['blocks'] ?? []) as $b) {
                if (empty($b['type'])) {
                    continue;
                }
                $blocks[] = ['type' => $b['type'], 'ref' => $b['ref'] ?? null, 'title' => $b['title'] ?? null, 'text' => $b['text'] ?? null]
                    + ($b['type'] === 'kpis' ? ['refs' => $kpis] : []);
            }
            $g = [
                'mode' => 'story', 'enabled' => true, 'size' => $data['size'], 'title' => $data['title'] ?? null, 'subtitle' => $data['subtitle'] ?? null,
                'blocks' => $blocks, 'image' => $spec['infographic']['image'] ?? null,
            ];
        }

        $spec['infographic'] = $g;
        [$version, $errors] = $this->dashboards->saveVersion($dashboard, $spec, $request->user(), 'user', 'แก้ไขอินโฟกราฟิก');
        if (! $version) {
            return back()->with('error', implode(' · ', $errors));
        }
        if (empty($version->spec['infographic']['enabled'])) {
            return back()->with('error', 'ไม่มีบล็อกที่ใช้ได้ — เลือกประเภทและตัวเลขจากรายงานอย่างน้อย 1 บล็อก');
        }

        return back()->with('status', $mode === 'auto' ? 'จัดอินโฟกราฟิกจากรายงานใหม่แล้ว' : 'บันทึกอินโฟกราฟิกแล้ว');
    }

    public function uploadImage(Request $request, Dashboard $dashboard): RedirectResponse
    {
        $this->authorizeEdit($request, $dashboard);
        $request->validate(['image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']], [
            'image.image' => 'ต้องเป็นไฟล์รูปภาพ', 'image.max' => 'รูปใหญ่เกิน 5 MB',
        ]);

        // รูปเดิมไม่ลบ เพื่อให้ย้อนกลับเวอร์ชันเก่าได้ (ลบพร้อม dashboard)
        $path = $request->file('image')->store('dashboards/'.$dashboard->id, 'local');
        $spec = $dashboard->currentVersion->spec;
        $spec['infographic'] = ['image' => $path] + ($spec['infographic'] ?? []);
        [$version, $errors] = $this->dashboards->saveVersion($dashboard, $spec, $request->user(), 'user', 'เปลี่ยนรูปหัวรายงาน');
        if (! $version) {
            Storage::disk('local')->delete($path);

            return back()->with('error', implode(' · ', $errors));
        }

        return back()->with('status', 'เปลี่ยนรูปหัวรายงานแล้ว');
    }

    public function image(Request $request, Dashboard $dashboard): BinaryFileResponse
    {
        $this->authorizeView($request, $dashboard);
        $path = $dashboard->currentVersion->spec['infographic']['image'] ?? null;
        abort_unless($path && str_starts_with($path, 'dashboards/'.$dashboard->id.'/') && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, max-age=3600']);
    }

    private function canEdit(Request $request, Dashboard $dashboard): bool
    {
        return $request->user()->can('update', $dashboard);
    }

    private function authorizeView(Request $request, Dashboard $dashboard): void
    {
        Gate::authorize('view', $dashboard);
    }

    private function authorizeEdit(Request $request, Dashboard $dashboard): void
    {
        Gate::authorize('update', $dashboard);
    }
}
