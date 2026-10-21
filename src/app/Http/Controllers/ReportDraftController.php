<?php

namespace App\Http\Controllers;

use App\Jobs\BuildReportJob;
use App\Jobs\GeneratePlanJob;
use App\Jobs\GenerateQuestionsJob;
use App\Models\Dataset;
use App\Models\ReportDraft;
use App\Services\Ai\AiGateway;
use App\Services\Report\AggregationEngine;
use App\Services\Report\DatasetProfile;
use App\Services\Report\ReportPlanner;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * ขั้นที่ 4: AI (หรือกฎมาตรฐาน) ถามว่าต้องการสรุปแบบไหน แล้ววางแผนรายงาน
 */
class ReportDraftController extends Controller
{
    /**
     * ขั้นที่ 4 (โหมด AI): ผู้ใช้อธิบายคร่าว ๆ ว่าอยากให้สรุปอะไร
     */
    public function brief(Request $request, Dataset $dataset, AiGateway $ai): View|RedirectResponse
    {
        Gate::authorize('update', $dataset);
        abort_unless($dataset->status === 'ready', 409, 'ข้อมูลยังนำเข้าไม่เสร็จ');

        $availability = $ai->availability($request->user());
        if (! $availability['available']) {
            return redirect()->route('datasets.show', $dataset)->with('error', $availability['reason']);
        }

        return view('drafts.brief', [
            'dataset' => $dataset,
            'needConsent' => ! $request->user()->ai_consent_at,
            'estimate' => app(ReportPlanner::class)->estimatePlanTokens($dataset),
            'source' => $availability['source'],
            'examples' => $this->examples($dataset),
            'insights' => array_slice(app(DatasetProfile::class)->insights($dataset)['insights'], 0, 8),
        ]);
    }

    /**
     * ตัวอย่างคำอธิบายที่กดเติมได้ — สร้างจากชื่อคอลัมน์จริง ไม่ผูกกับงานด้านใดด้านหนึ่ง
     *
     * @return list<string>
     */
    private function examples(Dataset $dataset): array
    {
        $cols = $dataset->columns()->where('is_hidden', false)->get();
        $cat = $cols->first(fn ($c) => in_array($c->type(), ['category', 'code'], true));
        $num = $cols->first(fn ($c) => $c->isNumeric());
        $date = $cols->first(fn ($c) => $c->type() === 'date');

        return array_values(array_filter([
            'สรุปภาพรวมสำหรับผู้บริหาร เน้นประเด็นที่ต้องตัดสินใจ',
            $cat && $num ? "เปรียบเทียบ {$num->display_name} แยกตาม {$cat->display_name} และชี้จุดที่สูง/ต่ำผิดปกติ" : null,
            $date && $num ? "ดูแนวโน้ม {$num->display_name} ตามช่วงเวลา" : null,
            $cat ? "จัดอันดับ {$cat->display_name} ที่มีจำนวนมากที่สุด 10 อันดับ" : null,
        ]));
    }

    public function start(Request $request, Dataset $dataset): RedirectResponse|View
    {
        Gate::authorize('update', $dataset);
        abort_unless($dataset->status === 'ready', 409, 'ข้อมูลยังนำเข้าไม่เสร็จ');

        $data = $request->validate([
            'mode' => ['required', Rule::in(['ai', 'rule'])],
            'consent' => ['nullable', 'boolean'],
            'send_samples' => ['nullable', 'boolean'],
            'brief' => ['nullable', 'string', 'max:1500'],
        ]);
        $useBrief = $data['mode'] === 'ai' && $request->has('brief');
        $mode = $data['mode'];
        $user = $request->user();

        // ก่อนส่งข้อมูลให้ AI ครั้งแรก ต้องรับทราบว่าอะไรถูกส่ง (spec FR-7.3)
        if ($mode === 'ai' && ! $user->ai_consent_at) {
            if (! $request->boolean('consent')) {
                return $useBrief
                    ? redirect()->route('drafts.brief', $dataset)->withInput()->with('error', 'กรุณาติ๊กรับทราบก่อนใช้ AI')
                    : view('drafts.consent', ['dataset' => $dataset]);
            }
            $user->forceFill(['ai_consent_at' => now()])->save();
            Audit::log('ai.consent', summary: 'ยอมรับการส่งข้อมูลสรุปให้ AI');
        }

        $draft = ReportDraft::create([
            'user_id' => $user->id, 'dataset_id' => $dataset->id, 'mode' => $mode, 'status' => $useBrief ? 'planning' : 'asking',
            'brief' => $useBrief ? trim((string) ($data['brief'] ?? '')) : null,
            'send_samples' => ! $request->has('send_samples') || $request->boolean('send_samples'),
        ]);
        $useBrief ? GeneratePlanJob::dispatch($draft->id, []) : GenerateQuestionsJob::dispatch($draft->id);

        return redirect()->route('drafts.show', $draft);
    }

    public function show(ReportDraft $draft): View|RedirectResponse
    {
        $this->authorizeDraft($draft);

        if ($draft->status === 'done' && $draft->dashboard_id) {
            return redirect()->route('dashboards.show', $draft->dashboard_id);
        }

        $review = null;
        if ($draft->status === 'review' && $draft->planned_spec) {
            $spec = array_diff_key($draft->planned_spec, ['_source' => 1, '_insights_used' => 1, 'infographic' => 1]);
            $used = $draft->planned_spec['_insights_used'] ?? [];
            $review = [
                'spec' => $spec,
                'computed' => app(AggregationEngine::class)->compute($draft->dataset, $spec),
                'source' => $draft->planned_spec['_source'] ?? 'ai',
                // ซ่อนข้อสังเกตที่อยู่ในแผนแล้ว (อ้างด้วย from หรือสูตรเดียวกัน)
                'extra' => collect(app(DatasetProfile::class)->insights($draft->dataset)['insights'])
                    ->reject(fn ($i) => in_array($i['id'], $used, true) || in_array($this->signature($i['widget']), array_map(fn ($w) => $this->signature($w), $spec['widgets']), true))
                    ->values(),
            ];
        }

        return view('drafts.show', ['draft' => $draft, 'dataset' => $draft->dataset, 'review' => $review]);
    }

    /**
     * ยืนยันแผน → สร้างรายงานจริง (เฉพาะส่วนที่เลือก + ข้อสังเกตที่ติ๊กเพิ่ม)
     */
    public function approve(Request $request, ReportDraft $draft): RedirectResponse
    {
        $this->authorizeDraft($draft);
        abort_unless($draft->status === 'review', 409);
        $data = $request->validate(['keep' => ['nullable', 'array'], 'keep.*' => ['string', 'max:40'], 'add' => ['nullable', 'array'], 'add.*' => ['string', 'max:10']]);
        if (empty($data['keep']) && empty($data['add'])) {
            return back()->with('error', 'เลือกอย่างน้อย 1 ส่วนที่ต้องการในรายงาน');
        }

        $draft->update(['status' => 'building', 'error_message' => null]);
        BuildReportJob::dispatch($draft->id, array_values($data['keep'] ?? []), array_values($data['add'] ?? []));

        return redirect()->route('drafts.show', $draft);
    }

    /**
     * ขอให้ AI ปรับแผนตามข้อความ (ยังไม่สร้างรายงาน)
     */
    public function revise(Request $request, ReportDraft $draft): RedirectResponse
    {
        $this->authorizeDraft($draft);
        abort_unless($draft->status === 'review' && $draft->mode === 'ai', 409);
        $data = $request->validate(['instruction' => ['required', 'string', 'min:5', 'max:800']], [
            'instruction.required' => 'กรุณาพิมพ์สิ่งที่อยากให้ปรับ', 'instruction.min' => 'กรุณาอธิบายให้ชัดขึ้นอีกนิด',
        ]);

        $draft->update(['instruction' => trim($data['instruction']), 'status' => 'planning', 'error_message' => null]);
        GeneratePlanJob::dispatch($draft->id, $draft->answers ?? []);

        return redirect()->route('drafts.show', $draft);
    }

    public function answer(Request $request, ReportDraft $draft): RedirectResponse
    {
        $this->authorizeDraft($draft);
        abort_unless($draft->status === 'questions', 409);

        $answers = [];
        foreach ($draft->questions ?? [] as $q) {
            $value = $request->input('answers.'.$q['id']);
            $allowed = collect($q['options'] ?? [])->pluck('value')->map(fn ($v) => (string) $v)->all();

            $answers[$q['id']] = match ($q['type']) {
                'single_choice' => $value === null ? null : (in_array((string) $value, $allowed, true) ? (string) $value : throw ValidationException::withMessages(['answers.'.$q['id'] => 'ตัวเลือกไม่ถูกต้อง'])),
                'multi_choice' => array_values(array_intersect(array_map('strval', (array) $value), $allowed)),
                default => is_string($value) ? mb_substr(trim($value), 0, 500) : null,
            };
        }

        $draft->update(['answers' => $answers, 'status' => 'planning']);
        GeneratePlanJob::dispatch($draft->id, $answers);

        return redirect()->route('drafts.show', $draft);
    }

    public function retry(Request $request, ReportDraft $draft): RedirectResponse
    {
        $this->authorizeDraft($draft);
        $mode = $request->validate(['mode' => ['required', Rule::in(['ai', 'rule'])]])['mode'];

        if ($draft->kind === 'refine' || ($mode === 'ai' && $draft->brief !== null && ! $draft->questions)) {
            $draft->update(['mode' => 'ai', 'status' => 'planning', 'error_message' => null, 'notices' => null]);
            GeneratePlanJob::dispatch($draft->id, []);
        } elseif ($draft->answers && $draft->questions && $draft->status === 'failed') {
            $draft->update(['mode' => $mode, 'status' => 'planning', 'error_message' => null]);
            GeneratePlanJob::dispatch($draft->id, $draft->answers);
        } else {
            $draft->update(['mode' => $mode, 'status' => 'asking', 'questions' => null, 'answers' => null, 'error_message' => null, 'notices' => null]);
            GenerateQuestionsJob::dispatch($draft->id);
        }

        return redirect()->route('drafts.show', $draft);
    }

    /**
     * ลายเซ็นของ widget (ชนิด + สูตร + มิติ + ตัวกรอง) ใช้เทียบว่าซ้ำกันไหม
     *
     * @param  array<string, mixed>  $w
     */
    private function signature(array $w): string
    {
        $m = $w['measures'] ?? (isset($w['measure']) ? [$w['measure']] : []);

        return md5(json_encode([in_array($w['type'], ['kpi', 'gauge'], true) ? 'single' : 'chart', $m, $w['dimension'] ?? null, $w['filters'] ?? []]));
    }

    private function authorizeDraft(ReportDraft $draft): void
    {
        abort_unless($draft->user_id === request()->user()->id, 403);
    }
}
