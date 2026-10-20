<?php

namespace App\Http\Controllers;

use App\Jobs\GeneratePlanJob;
use App\Jobs\GenerateQuestionsJob;
use App\Models\Dataset;
use App\Models\ReportDraft;
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
    public function start(Request $request, Dataset $dataset): RedirectResponse
    {
        Gate::authorize('update', $dataset);
        abort_unless($dataset->status === 'ready', 409, 'ข้อมูลยังนำเข้าไม่เสร็จ');

        $mode = $request->validate(['mode' => ['required', Rule::in(['ai', 'rule'])]])['mode'];
        $draft = ReportDraft::create(['user_id' => $request->user()->id, 'dataset_id' => $dataset->id, 'mode' => $mode, 'status' => 'asking']);
        GenerateQuestionsJob::dispatch($draft->id);

        return redirect()->route('drafts.show', $draft);
    }

    public function show(ReportDraft $draft): View|RedirectResponse
    {
        $this->authorizeDraft($draft);

        if ($draft->status === 'done' && $draft->dashboard_id) {
            return redirect()->route('dashboards.show', $draft->dashboard_id);
        }

        return view('drafts.show', ['draft' => $draft, 'dataset' => $draft->dataset]);
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

        if ($draft->answers && $draft->questions && $draft->status === 'failed') {
            $draft->update(['mode' => $mode, 'status' => 'planning', 'error_message' => null]);
            GeneratePlanJob::dispatch($draft->id, $draft->answers);
        } else {
            $draft->update(['mode' => $mode, 'status' => 'asking', 'questions' => null, 'answers' => null, 'error_message' => null, 'notices' => null]);
            GenerateQuestionsJob::dispatch($draft->id);
        }

        return redirect()->route('drafts.show', $draft);
    }

    private function authorizeDraft(ReportDraft $draft): void
    {
        abort_unless($draft->user_id === request()->user()->id, 403);
    }
}
