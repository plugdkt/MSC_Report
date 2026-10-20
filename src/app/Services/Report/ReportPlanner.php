<?php

namespace App\Services\Report;

use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\ReportDraft;
use App\Services\Ai\AiException;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiResponse;
use App\Services\Ai\JsonExtractor;
use Illuminate\Support\Facades\DB;

/**
 * ขั้นตอนถาม-ตอบและวางแผนรายงาน: ใช้ AI ถ้าทำได้ และถอยกลับไปโหมดไม่ใช้ AI เมื่อ AI ใช้ไม่ได้หรือตอบผิดรูปแบบ
 */
class ReportPlanner
{
    private const MAX_REPAIRS = 2;

    public function __construct(
        private readonly AiGateway $ai,
        private readonly DatasetProfile $profiles,
        private readonly Prompts $prompts,
        private readonly RuleQuestions $ruleQuestions,
        private readonly QuestionValidator $questionValidator,
        private readonly SpecValidator $specValidator,
        private readonly RulePlanner $rulePlanner,
        private readonly AggregationEngine $engine,
        private readonly DashboardService $dashboards,
        private readonly InfographicSuggester $infographics,
        private readonly NarrativeService $narratives,
    ) {}

    /**
     * ประมาณ token ของขั้นถามคำถาม (แสดงก่อนผู้ใช้กด — spec §6.5)
     */
    public function estimateClarifyTokens(ReportDraft|Dataset $target): int
    {
        $dataset = $target instanceof ReportDraft ? $target->dataset : $target;
        $request = $this->prompts->clarify($this->profiles->build($dataset));

        return $request->estimatedInputTokens() + (int) config('ai.max_output_tokens.clarify') / 2;
    }

    public function ask(ReportDraft $draft): void
    {
        $dataset = $draft->dataset;
        $profile = $this->profiles->build($dataset);
        $base = $this->ruleQuestions->build($profile);

        if ($draft->mode === 'ai') {
            try {
                [$questions, $understanding] = $this->askAi($draft, $profile);
                $keep = collect($base)->whereIn('id', ['audience'])->all();
                $draft->fill(['questions' => [...$questions, ...$keep], 'understanding' => $understanding, 'status' => 'questions'])->save();

                return;
            } catch (AiException $e) {
                $draft->mode = 'rule';
                $draft->addNotice('ใช้คำถามมาตรฐานแทน เพราะ: '.$e->getMessage());
            }
        }

        $draft->fill(['questions' => $base, 'status' => 'questions'])->save();
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public function plan(ReportDraft $draft, array $answers): Dashboard
    {
        $dataset = $draft->dataset;
        $columns = $dataset->columns()->get();
        $profile = $this->profiles->build($dataset, withSamples: false);
        $defaultTitle = 'สรุป'.$dataset->name;
        $source = 'rule';
        $spec = null;

        if ($draft->mode === 'ai') {
            try {
                $spec = $this->planAi($draft, $profile, $answers, $columns, $defaultTitle);
                $source = 'ai';
            } catch (AiException $e) {
                $draft->addNotice('สร้างรายงานด้วยกฎมาตรฐานแทน เพราะ: '.$e->getMessage());
            }
        }

        if ($spec === null) {
            [$spec, $errors] = $this->specValidator->validate($this->rulePlanner->plan($profile, $answers), $columns, $defaultTitle);
            if ($spec['widgets'] === []) {
                throw new \RuntimeException('สร้างรายงานจากข้อมูลนี้ไม่ได้: '.implode('; ', $errors));
            }
        }

        $spec['infographic'] ??= $this->infographics->suggest($dataset, $draft->user->div_name);

        $dashboard = DB::transaction(function () use ($draft, $dataset, $spec, $source, $answers) {
            $dashboard = Dashboard::create(['user_id' => $draft->user_id, 'title' => $spec['title'], 'dataset_id' => $dataset->id]);
            [$version, $errors] = $this->dashboards->saveVersion($dashboard, $spec, $draft->user, $source,
                $source === 'ai' ? 'สร้างโดย AI จากคำตอบของผู้ใช้' : 'สร้างด้วยกฎมาตรฐาน (ไม่ใช้ AI)');
            if (! $version) {
                throw new \RuntimeException(implode('; ', $errors));
            }
            $draft->fill(['answers' => $answers, 'dashboard_id' => $dashboard->id])->save();

            return $dashboard;
        });

        // บทสรุปผู้บริหาร: ถ้าใช้ AI ไม่ได้ ใช้แบบมาตรฐาน — ไม่ทำให้การสร้างรายงานล้มเหลว
        // ใช้ AI เขียนบทสรุปเฉพาะเมื่อขั้นวางแผนใช้ AI สำเร็จ (ถ้า AI มีปัญหาอยู่แล้วจะไม่เสีย token เพิ่ม)
        $notice = $this->narratives->generate($dashboard->fresh()->currentVersion, $draft->user, $source === 'ai');
        if ($notice) {
            $draft->addNotice($notice);
        }
        $draft->fill(['status' => 'done'])->save();

        return $dashboard;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{0: list<array<string, mixed>>, 1: ?string}
     */
    private function askAi(ReportDraft $draft, array $profile): array
    {
        $ids = array_column($profile['columns'], 'id');
        $request = $this->prompts->clarify($profile);

        [$data, $response, $request] = $this->callWithRepair($draft, $request, function (?array $data) use ($ids) {
            [, $errors] = $this->questionValidator->validate($data, $ids);

            return $errors;
        });
        [$questions, , $understanding] = $this->questionValidator->validate($data, $ids);
        $this->ai->storeCache($request, $response);

        return [$questions, $understanding];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    private function planAi(ReportDraft $draft, array $profile, array $answers, $columns, string $defaultTitle): array
    {
        $request = $this->prompts->plan($profile, $this->describeAnswers($draft->questions ?? [], $answers));

        [$data, $response, $request] = $this->callWithRepair($draft, $request, function (?array $data) use ($columns, $defaultTitle) {
            [$spec, $errors] = $this->specValidator->validate($data, $columns, $defaultTitle);

            return $spec === [] || $spec['widgets'] === [] ? ($errors ?: ['ไม่มี widget ที่ใช้ได้']) : $errors;
        });

        [$spec] = $this->specValidator->validate($data, $columns, $defaultTitle);
        $this->ai->storeCache($request, $response);

        return $spec;
    }

    /**
     * เรียก AI แล้วตรวจ ถ้าผิดส่งข้อผิดพลาดให้ AI แก้ (สูงสุด 2 ครั้ง) — spec FR-4.3
     *
     * @param  callable(?array): list<string>  $validate
     * @return array{0: array<string, mixed>, 1: AiResponse, 2: AiRequest}
     */
    private function callWithRepair(ReportDraft $draft, AiRequest $request, callable $validate): array
    {
        for ($attempt = 0; $attempt <= self::MAX_REPAIRS; $attempt++) {
            $response = $this->ai->complete($draft->user, $request, $draft->dataset, useCache: $attempt === 0);
            if ($response->truncated) {
                // ให้แก้ซ้ำไม่ช่วย (จะถูกตัดอีก) — หยุดทันทีเพื่อไม่เสีย token
                throw new AiException(AiException::BAD_RESPONSE, 'คำตอบของ AI ยาวเกินเพดาน '.number_format((int) config('ai.max_output_tokens.'.$request->stage)).' token — ให้ผู้ดูแลระบบเพิ่มเพดานหรือเลือกโมเดลอื่น');
            }
            $data = JsonExtractor::decode($response->text);
            $errors = $data === null ? ['คำตอบไม่ใช่ JSON ที่ถูกต้อง'] : $validate($data);

            if ($errors === []) {
                return [$data, $response, $request];
            }
            if ($attempt === self::MAX_REPAIRS) {
                break;
            }
            $request = $this->prompts->repair($request, $response->text, $errors);
        }

        throw new AiException(AiException::BAD_RESPONSE, 'AI ตอบไม่ตรงรูปแบบหลังให้แก้ '.self::MAX_REPAIRS.' ครั้ง');
    }

    /**
     * @param  list<array<string, mixed>>  $questions
     * @param  array<string, mixed>  $answers
     * @return list<array{question:string, answer:string}>
     */
    public function describeAnswers(array $questions, array $answers): array
    {
        $out = [];
        foreach ($questions as $q) {
            $a = $answers[$q['id']] ?? null;
            if ($a === null || $a === '' || $a === []) {
                continue;
            }
            $labels = collect($q['options'] ?? [])->pluck('label', 'value');
            $text = collect((array) $a)->map(fn ($v) => $v === RuleQuestions::AI_DECIDE ? 'ให้ AI เลือกให้' : ($labels[$v] ?? (string) $v))->implode(', ');
            $out[] = ['question' => $q['text'], 'answer' => $text];
        }

        return $out;
    }
}
