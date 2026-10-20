<?php

namespace Tests\Feature;

use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\ReportDraft;
use App\Models\User;
use Database\Seeders\CodeSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AiFake;
use Tests\Support\BudgetWorkbook;
use Tests\TestCase;

/**
 * M6: อธิบายอิสระแทนการตอบคำถาม — วางแผนรอบเดียว, AI ถามเพิ่มเมื่อกำกวม, ปรับรายงานด้วยข้อความ
 */
class BriefFlowTest extends TestCase
{
    use AiFake;
    use RefreshDatabase;

    private User $user;

    private Dataset $dataset;

    /** @var list<array<string, mixed>> */
    private array $queue = [];

    private bool $aiFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CodeSetSeeder::class);
        $this->user = User::factory()->create(['ai_consent_at' => now()]);

        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        BudgetWorkbook::create($path);
        $this->actingAs($this->user)->post(route('datasets.store'), ['file' => new UploadedFile($path, 'budget.xlsx', null, null, true)]);
        $this->dataset = Dataset::firstOrFail();
        $this->actingAs($this->user)->post(route('datasets.import', $this->dataset), ['sheet' => 'ข้อมูล', 'header_start' => 1, 'header_end' => 1]);
        $this->dataset->refresh();
        $this->configureAi();
        $this->giveKey($this->user);
    }

    private function slug(string $source): string
    {
        return $this->dataset->columns->firstWhere('source_name', $source)->slug;
    }

    /**
     * @return array<string, mixed>
     */
    private function planSpec(): array
    {
        $used = $this->slug('ใช้จริง');

        return ['title' => 'สรุปตามที่ขอ', 'widgets' => [
            ['id' => 'w1', 'type' => 'kpi', 'title' => 'ใช้จริงรวม', 'measure' => ['column' => $used, 'agg' => 'sum']],
            ['id' => 'w2', 'type' => 'bar', 'title' => 'ใช้จริงตามหมวด', 'dimension' => ['column' => $this->slug('คำอธิบาย')], 'measures' => [['column' => $used, 'agg' => 'sum']]],
        ]];
    }

    /**
     * ตอบ narrative แบบว่าง (ตกไปใช้บทสรุปมาตรฐาน) และ plan ตามที่กำหนด — เก็บ request ไว้ตรวจ
     *
     * @param  list<array<string, mixed>>  $plans
     */
    private function fakeAi(array $plans): void
    {
        $this->queue = $plans;
        $this->aiFails = false;
        Http::fake([self::AI_URL => function (Request $request) {
            if ($this->aiFails) {
                return Http::response(['error' => ['message' => 'bad']], 400);
            }
            if (str_contains($request['messages'][0]['content'] ?? '', 'บทสรุปสำหรับผู้บริหาร')) {
                return self::chat(['summary' => []]);
            }

            return self::chat(array_shift($this->queue) ?? $this->planSpec());
        }]);
    }

    private function planRequests(): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])->filter(fn (Request $r) => preg_match('/งาน: (ออกแบบ|ปรับ) Dashboard/u', $r['messages'][0]['content']))->values()->all();
    }

    public function test_brief_page_shows_examples_and_consent_when_needed(): void
    {
        $this->user->update(['ai_consent_at' => null]);

        $this->actingAs($this->user)->get(route('drafts.brief', $this->dataset))
            ->assertOk()->assertSee('อยากให้สรุปอะไร')->assertSee('รับทราบและยินยอม')->assertSee('เปรียบเทียบ');

        $this->fakeAi([$this->planSpec()]);
        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => 'x'])
            ->assertRedirect(route('drafts.brief', $this->dataset))->assertSessionHas('error');
        $this->assertSame(0, ReportDraft::count());

        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => 'x', 'consent' => 1])->assertRedirect();
        $this->assertNotNull($this->user->fresh()->ai_consent_at);
    }

    public function test_brief_page_redirects_when_ai_is_unavailable(): void
    {
        $this->user->aiCredential()->delete();
        $this->actingAs($this->user)->get(route('drafts.brief', $this->dataset))->assertRedirect(route('datasets.show', $this->dataset))->assertSessionHas('error');
    }

    public function test_brief_creates_report_with_a_single_planning_call(): void
    {
        $this->fakeAi([$this->planSpec()]);
        $brief = 'อยากเห็นยอดใช้จริงแยกตามหมวด สำหรับรองคณบดี';

        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => $brief, 'send_samples' => 0])->assertRedirect();

        $draft = ReportDraft::firstOrFail();
        $this->assertSame($brief, $draft->brief);
        $this->assertFalse($draft->send_samples);
        $this->assertSame('done', $draft->status);
        $this->assertNull($draft->questions);

        $plans = $this->planRequests();
        $this->assertCount(1, $plans, 'ต้องเรียก AI วางแผนเพียงรอบเดียว (ไม่มีรอบถามคำถาม)');
        $this->assertStringContainsString($brief, $plans[0]['messages'][1]['content'] ?? '');
        $this->assertStringContainsString('สิ่งที่ผู้ใช้ต้องการ', json_encode($plans[0]['messages'], JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('need_clarification', $plans[0]['messages'][0]['content']);

        $dashboard = Dashboard::findOrFail($draft->dashboard_id);
        $this->assertSame('ai', $dashboard->currentVersion->source);
        $this->assertSame('สรุปตามที่ขอ', $dashboard->title);
        $this->assertEquals(BudgetWorkbook::TOTAL_USED, $dashboard->currentVersion->computed_data['w1']['value']);
    }

    public function test_empty_brief_still_plans_and_never_asks_questions(): void
    {
        $this->fakeAi([$this->planSpec()]);
        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => ''])->assertRedirect();

        $draft = ReportDraft::firstOrFail();
        $this->assertSame('done', $draft->status);
        $plan = $this->planRequests()[0];
        $this->assertStringNotContainsString('need_clarification', $plan['messages'][0]['content']);
        $this->assertStringContainsString('ไม่ได้ระบุ', json_encode($plan['messages'], JSON_UNESCAPED_UNICODE));
    }

    public function test_ai_may_ask_clarifying_question_once_then_plans(): void
    {
        $this->fakeAi([
            ['understanding' => 'ข้อมูลการใช้จ่าย', 'need_clarification' => ['questions' => [
                ['id' => 'which', 'type' => 'single_choice', 'text' => 'หมายถึงยอดใด?', 'options' => [['value' => 'used', 'label' => 'ยอดใช้จริง'], ['value' => 'budget', 'label' => 'ยอดงบประมาณ']]],
            ]]],
            $this->planSpec(),
        ]);

        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => 'สรุปยอดให้หน่อย'])->assertRedirect();
        $draft = ReportDraft::firstOrFail();
        $this->assertSame('questions', $draft->status);
        $this->assertSame('which', $draft->questions[0]['id']);
        $this->assertNull($draft->dashboard_id);

        $this->actingAs($this->user)->get(route('drafts.show', $draft))->assertOk()->assertSee('หมายถึงยอดใด?');
        $this->actingAs($this->user)->post(route('drafts.answer', $draft), ['answers' => ['which' => 'used']])->assertRedirect();

        $draft->refresh();
        $this->assertSame('done', $draft->status);
        $plans = $this->planRequests();
        $this->assertCount(2, $plans);
        $this->assertStringNotContainsString('need_clarification', $plans[1]['messages'][0]['content'], 'ถามเพิ่มได้ครั้งเดียว');
        $this->assertStringContainsString('ยอดใช้จริง', json_encode($plans[1]['messages'], JSON_UNESCAPED_UNICODE));
    }

    public function test_ai_failure_with_brief_falls_back_to_rule_planner(): void
    {
        Http::fake([self::AI_URL => Http::response(['error' => ['message' => 'bad']], 400)]);
        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => 'สรุปให้หน่อย'])->assertRedirect();

        $draft = ReportDraft::firstOrFail();
        $this->assertSame('done', $draft->status);
        $this->assertSame('rule', Dashboard::findOrFail($draft->dashboard_id)->currentVersion->source);
        $this->assertNotEmpty($draft->notices);
    }

    public function test_refine_creates_new_version_from_instruction(): void
    {
        $this->fakeAi([$this->planSpec()]);
        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => 'สรุป']);
        $dashboard = Dashboard::firstOrFail();
        $before = $dashboard->currentVersion;

        $refined = $this->planSpec();
        $refined['widgets'][] = ['id' => 'w3', 'type' => 'pie', 'title' => 'สัดส่วนใช้จริง', 'dimension' => ['column' => $this->slug('คำอธิบาย')], 'measure' => ['column' => $this->slug('ใช้จริง'), 'agg' => 'sum']];
        $this->queue = [$refined];

        $this->actingAs($this->user)->post(route('dashboards.refine', $dashboard), ['instruction' => 'เพิ่มกราฟวงกลมสัดส่วน'])->assertRedirect();

        $draft = ReportDraft::where('kind', 'refine')->firstOrFail();
        $this->assertSame('done', $draft->status);
        $this->actingAs($this->user)->get(route('drafts.show', $draft))->assertRedirect(route('dashboards.show', $dashboard));

        $after = $dashboard->fresh()->currentVersion;
        $this->assertSame($before->version + 1, $after->version);
        $this->assertSame('ai', $after->source);
        $this->assertStringContainsString('เพิ่มกราฟวงกลมสัดส่วน', $after->note);
        $this->assertCount(3, $after->spec['widgets']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.refined']);

        $refineCall = collect($this->planRequests())->last();
        $this->assertStringContainsString('เพิ่มกราฟวงกลมสัดส่วน', $refineCall['messages'][1]['content']);
        $this->assertStringContainsString('รายงานปัจจุบัน', $refineCall['messages'][1]['content']);
    }

    public function test_refine_is_owner_only_and_needs_consent(): void
    {
        $this->fakeAi([$this->planSpec()]);
        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => 'สรุป']);
        $dashboard = Dashboard::firstOrFail();

        $other = User::factory()->create(['ai_consent_at' => now()]);
        $this->giveKey($other, 'sk-other-1234567890abcd');
        $this->actingAs($other)->post(route('dashboards.refine', $dashboard), ['instruction' => 'เพิ่มกราฟ'])->assertForbidden();

        $this->user->update(['ai_consent_at' => null]);
        $this->actingAs($this->user)->post(route('dashboards.refine', $dashboard), ['instruction' => 'เพิ่มกราฟวงกลม'])->assertSessionHas('error');
        $this->assertSame(0, ReportDraft::where('kind', 'refine')->count());

        $this->user->update(['ai_consent_at' => now()]);
        $this->actingAs($this->user)->post(route('dashboards.refine', $dashboard), ['instruction' => 'ก'])->assertSessionHasErrors('instruction');
    }

    public function test_refine_failure_keeps_current_version(): void
    {
        $this->fakeAi([$this->planSpec()]);
        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => 'สรุป']);
        $dashboard = Dashboard::firstOrFail();
        $version = $dashboard->current_version_id;

        $this->aiFails = true;
        $this->actingAs($this->user)->post(route('dashboards.refine', $dashboard), ['instruction' => 'เพิ่มกราฟวงกลม']);

        $draft = ReportDraft::where('kind', 'refine')->firstOrFail();
        $this->assertSame('failed', $draft->status);
        $this->assertSame($version, $dashboard->fresh()->current_version_id);
        $this->actingAs($this->user)->get(route('drafts.show', $draft))->assertOk()->assertSee('กลับไปที่รายงาน');
    }
}
