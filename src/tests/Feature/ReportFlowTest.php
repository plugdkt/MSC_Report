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

class ReportFlowTest extends TestCase
{
    use AiFake;
    use RefreshDatabase;

    private User $user;

    private Dataset $dataset;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CodeSetSeeder::class);
        $this->user = User::factory()->create();

        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        BudgetWorkbook::create($path);
        $this->actingAs($this->user)->post(route('datasets.store'), ['file' => new UploadedFile($path, 'budget.xlsx', null, null, true)]);
        $this->dataset = Dataset::firstOrFail();
        $this->actingAs($this->user)->post(route('datasets.import', $this->dataset), ['sheet' => 'ข้อมูล', 'header_start' => 1, 'header_end' => 1]);
        $this->dataset->refresh();
    }

    private function slug(string $source): string
    {
        return $this->dataset->columns->firstWhere('source_name', $source)->slug;
    }

    private function start(string $mode): ReportDraft
    {
        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => $mode])->assertRedirect();

        return ReportDraft::latest('id')->firstOrFail();
    }

    public function test_rule_mode_without_ai_builds_dashboard_with_correct_numbers(): void
    {
        Http::fake();
        $draft = $this->start('rule');
        $this->assertSame('questions', $draft->status);
        $this->assertSame(['measures', 'breakdown', 'audience'], array_column($draft->questions, 'id'));

        $this->actingAs($this->user)->get(route('drafts.show', $draft))->assertOk()->assertSee('ต้องการเน้นตัวเลขใด');
        $this->actingAs($this->user)->post(route('drafts.answer', $draft), ['answers' => [
            'measures' => [$this->slug('ใช้จริง'), $this->slug('งบประมาณ')],
            'breakdown' => $this->slug('คำอธิบาย').':group',
            'audience' => 'supervisor',
        ]])->assertRedirect(route('drafts.show', $draft));

        $draft->refresh();
        $this->assertSame('done', $draft->status);
        $version = Dashboard::findOrFail($draft->dashboard_id)->currentVersion;
        $this->assertSame('rule', $version->source);

        $kpis = collect($version->spec['widgets'])->where('type', 'kpi')->pluck('id');
        $values = $kpis->map(fn ($id) => $version->computed_data[$id]['value'])->all();
        $this->assertEquals([4, BudgetWorkbook::TOTAL_USED, BudgetWorkbook::TOTAL_BUDGET], $values);

        $bar = collect($version->spec['widgets'])->firstWhere('type', 'bar');
        $this->assertSame(['งบบุคลากร', 'งบดำเนินงาน'], $version->computed_data[$bar['id']]['categories']);
        $this->assertEquals([132000, 115700.5], $version->computed_data[$bar['id']]['series'][0]['data']);

        $this->actingAs($this->user)->get(route('drafts.show', $draft))->assertRedirect(route('dashboards.show', $draft->dashboard_id));
        $this->actingAs($this->user)->get(route('dashboards.show', $draft->dashboard_id))->assertOk()->assertSee('กฎมาตรฐาน');
        Http::assertNothingSent();
    }

    public function test_ai_mode_asks_questions_and_repairs_invalid_plan(): void
    {
        $this->configureAi();
        $this->giveKey($this->user);
        $this->dataset->columns()->where('source_name', 'รายการ')->update(['is_pii' => true]);
        $used = $this->slug('ใช้จริง');
        $budget = $this->slug('งบประมาณ');
        $category = $this->slug('คำอธิบาย');

        Http::fakeSequence(self::AI_URL)
            ->pushResponse(self::chat(['understanding' => 'ข้อมูลการใช้จ่ายงบประมาณ 4 รายการ', 'questions' => [
                ['id' => 'focus', 'type' => 'single_choice', 'text' => 'ต้องการเน้นเรื่องใด?', 'options' => [['value' => 'used', 'label' => 'ยอดใช้จริง'], ['value' => 'remain', 'label' => 'ยอดคงเหลือ']], 'related_columns' => [$used, 'c99']],
                ['id' => 'target', 'type' => 'text', 'text' => 'มีเป้าหมายการเบิกจ่ายไหม?'],
            ]]))
            ->pushResponse(self::chat("```json\n".json_encode(['title' => 'สรุปงบ', 'widgets' => [['type' => 'kpi', 'title' => 'ใช้จริง', 'measure' => ['column' => 'c99', 'agg' => 'sum']]]])."\n```"))
            ->pushResponse(self::chat(['title' => 'สรุปการใช้งบประมาณ', 'widgets' => [
                ['id' => 'w1', 'type' => 'kpi', 'title' => 'ใช้จริงรวม', 'measure' => ['column' => $used, 'agg' => 'sum']],
                ['id' => 'w2', 'type' => 'gauge', 'title' => 'ร้อยละการใช้งบ', 'measure' => ['columns' => [$used], 'agg' => 'ratio', 'denominator' => ['columns' => [$budget]]], 'target' => 80],
                ['id' => 'w3', 'type' => 'bar', 'title' => 'ใช้จริงตามงบรายจ่าย', 'dimension' => ['column' => $category, 'group_by' => 'group'], 'measures' => [['column' => $used, 'agg' => 'sum']]],
            ]]));

        $draft = $this->start('ai');
        $this->assertSame('questions', $draft->status);
        $this->assertSame('ข้อมูลการใช้จ่ายงบประมาณ 4 รายการ', $draft->understanding);
        $this->assertSame(['focus', 'target', 'audience'], array_column($draft->questions, 'id'));
        $this->assertSame([$used], $draft->questions[0]['related_columns'], 'ตัดคอลัมน์ที่ไม่มีจริง');
        $this->assertContains('ai_decide', array_column($draft->questions[0]['options'], 'value'));

        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'], '<data>')
            && ! str_contains($r['messages'][1]['content'], 'ค่าจ้างรายเดือน'));

        $this->actingAs($this->user)->post(route('drafts.answer', $draft), ['answers' => ['focus' => 'used', 'target' => 'เบิกจ่าย 80%', 'audience' => 'executive']]);

        $version = Dashboard::findOrFail($draft->fresh()->dashboard_id)->currentVersion;
        $this->assertSame('ai', $version->source);
        $this->assertSame(BudgetWorkbook::TOTAL_USED, $version->computed_data['w1']['value']);
        $this->assertSame(round(BudgetWorkbook::TOTAL_USED / BudgetWorkbook::TOTAL_BUDGET * 100, 2), $version->computed_data['w2']['value']);
        $this->assertEquals(80, $version->computed_data['w2']['target']);

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => count($r['messages']) === 4 && str_contains($r['messages'][3]['content'], 'ไม่พบคอลัมน์ "c99"'));
        Http::assertSent(fn (Request $r) => str_contains($r['messages'][1]['content'] ?? '', 'เบิกจ่าย 80%'));
    }

    public function test_ai_errors_fall_back_to_rule_mode_with_notice(): void
    {
        $this->configureAi();
        $this->giveKey($this->user);
        Http::fake([self::AI_URL => Http::response(['error' => 'unauthorized'], 401)]);

        $draft = $this->start('ai');

        $this->assertSame('rule', $draft->mode);
        $this->assertSame('questions', $draft->status);
        $this->assertStringContainsString('API Key ใช้ไม่ได้', $draft->notices[0]);
        $this->assertSame('invalid', $this->user->aiCredential->fresh()->status);
    }

    public function test_plan_falls_back_to_rules_when_ai_keeps_answering_badly(): void
    {
        $this->configureAi();
        $this->giveKey($this->user);
        $draft = ReportDraft::create(['user_id' => $this->user->id, 'dataset_id' => $this->dataset->id, 'mode' => 'ai', 'status' => 'questions', 'questions' => []]);
        Http::fake([self::AI_URL => self::chat('ขออภัย ฉันไม่เข้าใจ')]);

        $this->actingAs($this->user)->post(route('drafts.answer', $draft), ['answers' => []]);

        $draft->refresh();
        $this->assertSame('done', $draft->status);
        $this->assertSame('rule', Dashboard::find($draft->dashboard_id)->currentVersion->source);
        $this->assertStringContainsString('กฎมาตรฐาน', $draft->notices[0]);
        Http::assertSentCount(3);
    }

    public function test_other_users_cannot_see_drafts_or_dashboards(): void
    {
        $draft = $this->start('rule');
        $this->actingAs($this->user)->post(route('drafts.answer', $draft), ['answers' => ['audience' => 'executive']]);
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('drafts.show', $draft))->assertForbidden();
        $this->actingAs($other)->get(route('dashboards.show', $draft->fresh()->dashboard_id))->assertForbidden();
        $this->actingAs($other)->post(route('drafts.start', $this->dataset), ['mode' => 'rule'])->assertForbidden();
    }

    public function test_dataset_page_offers_ai_with_token_estimate_only_when_available(): void
    {
        $this->actingAs($this->user)->get(route('datasets.show', $this->dataset))->assertSee('สร้างรายงาน (ไม่ใช้ AI)');

        $this->configureAi();
        $this->giveKey($this->user);
        $this->actingAs($this->user)->get(route('datasets.show', $this->dataset))->assertSee('ให้ AI ช่วยสรุป')->assertSee('token');
    }
}
