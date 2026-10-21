<?php

namespace Tests\Feature;

use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\ReportDraft;
use App\Models\User;
use App\Services\Report\DatasetProfile;
use App\Services\Report\InsightFinder;
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
 * M7: ข้อสังเกตจากข้อมูล (ไม่ใช้ AI) + ขั้นตรวจแผนก่อนสร้างรายงาน
 */
class PlanReviewTest extends TestCase
{
    use AiFake;
    use RefreshDatabase;

    private User $user;

    private Dataset $dataset;

    /** @var list<array<string, mixed>> */
    private array $queue = [];

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

        Http::fake([self::AI_URL => function (Request $request) {
            if (str_contains($request['messages'][0]['content'] ?? '', 'บทสรุปสำหรับผู้บริหาร')) {
                return self::chat(['summary' => []]);
            }

            return self::chat(array_shift($this->queue) ?? ['title' => 'x', 'widgets' => []]);
        }]);
    }

    private function slug(string $source): string
    {
        return $this->dataset->columns->firstWhere('source_name', $source)->slug;
    }

    private function insightId(string $needle): string
    {
        return collect(app(DatasetProfile::class)->insights($this->dataset)['insights'])->first(fn ($i) => str_contains($i['text'], $needle))['id'];
    }

    public function test_finder_detects_part_whole_structure_and_ratios(): void
    {
        $found = app(InsightFinder::class)->find($this->dataset);

        $this->assertSame([$this->slug('งบประมาณ')], $found['structure']['whole']);
        $this->assertEqualsCanonicalizing([$this->slug('ใช้จริง'), $this->slug('คงเหลือ')], $found['structure']['parts']);
        $texts = array_column($found['insights'], 'text');
        $this->assertContains('ร้อยละใช้จริงต่องบประมาณ ภาพรวม '.number_format(BudgetWorkbook::TOTAL_USED / BudgetWorkbook::TOTAL_BUDGET * 100, 1).'%', $texts);
        $this->assertNotEmpty(array_filter($texts, fn ($t) => str_contains($t, 'แยกตาม')));
        foreach ($found['insights'] as $i) {
            $this->assertMatchesRegularExpression('/^i\d+$/', $i['id']);
            $this->assertArrayHasKey('type', $i['widget']);
        }
    }

    public function test_profile_sent_to_ai_includes_structure_and_insights(): void
    {
        $profile = app(DatasetProfile::class)->build($this->dataset, withSamples: false);

        $this->assertStringContainsString('งบประมาณ =', $profile['structure']);
        $this->assertStringStartsWith('i1: ', $profile['insights'][0]);
    }

    public function test_ai_can_use_insight_widgets_and_user_reviews_before_building(): void
    {
        $ratio = $this->insightId('ร้อยละใช้จริงต่องบประมาณ');
        $this->queue = [['title' => 'ติดตามการใช้งบ', 'widgets' => [
            ['id' => 'w1', 'type' => 'kpi', 'title' => 'ใช้จริงรวม', 'measure' => ['column' => $this->slug('ใช้จริง'), 'agg' => 'sum'], 'purpose' => 'ใช้ไปเท่าไร'],
            ['id' => 'w2', 'from' => $ratio, 'purpose' => 'ใช้ไปกี่เปอร์เซ็นต์ของงบ'],
            ['id' => 'w3', 'from' => 'i999'],   // insight ที่ไม่มีจริง → ถูกตัด
        ]]];

        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => 'ใช้งบไปแค่ไหนแล้ว'])->assertRedirect();
        $draft = ReportDraft::firstOrFail();
        $this->assertSame('review', $draft->status);

        $w2 = collect($draft->planned_spec['widgets'])->firstWhere('id', 'w2');
        $this->assertSame('gauge', $w2['type']);
        $this->assertSame('ratio', $w2['measure']['agg']);
        $this->assertSame('ใช้ไปกี่เปอร์เซ็นต์ของงบ', $w2['purpose']);
        $this->assertSame([$ratio], $draft->planned_spec['_insights_used']);

        $page = $this->actingAs($this->user)->get(route('drafts.show', $draft))->assertOk();
        $page->assertSee('ตรวจแผนรายงานก่อนสร้าง')->assertSee('ตอบ: ใช้ไปเท่าไร')->assertSee('247,700.50')
            ->assertSee('ข้อสังเกตอื่นจากข้อมูล')->assertSee('ใช้งบไปแค่ไหนแล้ว');
        $this->assertSame(0, Dashboard::count());

        // เก็บแค่ w2 + เพิ่มข้อสังเกตอื่น 1 ข้อ
        $extra = collect(app(DatasetProfile::class)->insights($this->dataset)['insights'])->firstWhere('widget.type', 'bar')['id'];
        $this->actingAs($this->user)->post(route('drafts.approve', $draft), ['keep' => ['w2'], 'add' => [$extra, 'i999']])->assertRedirect();

        $draft->refresh();
        $this->assertSame('done', $draft->status);
        $spec = Dashboard::findOrFail($draft->dashboard_id)->currentVersion->spec;
        $this->assertSame(['w2', 'x'.$extra], array_column($spec['widgets'], 'id'));
        $this->assertEquals(round(BudgetWorkbook::TOTAL_USED / BudgetWorkbook::TOTAL_BUDGET * 100, 2), Dashboard::findOrFail($draft->dashboard_id)->currentVersion->computed_data['w2']['value']);
    }

    public function test_user_can_ask_ai_to_revise_plan_before_building(): void
    {
        $used = $this->slug('ใช้จริง');
        $this->queue = [
            ['title' => 'แผนแรก', 'widgets' => [['id' => 'w1', 'type' => 'kpi', 'title' => 'ใช้จริงรวม', 'measure' => ['column' => $used, 'agg' => 'sum']]]],
            ['title' => 'แผนที่ปรับแล้ว', 'widgets' => [
                ['id' => 'w1', 'type' => 'kpi', 'title' => 'ใช้จริงรวม', 'measure' => ['column' => $used, 'agg' => 'sum']],
                ['id' => 'w2', 'type' => 'bar', 'title' => 'ใช้จริงตามรายการ', 'dimension' => ['column' => $this->slug('รายการ')], 'measures' => [['column' => $used, 'agg' => 'sum']], 'purpose' => 'รายการไหนใช้มาก'],
            ]],
        ];
        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => 'สรุปการใช้งบ']);
        $draft = ReportDraft::firstOrFail();
        $this->assertSame('แผนแรก', $draft->planned_spec['title']);

        $this->actingAs($this->user)->post(route('drafts.revise', $draft), ['instruction' => 'เพิ่มการแยกตามรายการ'])->assertRedirect(route('drafts.show', $draft));

        $draft->refresh();
        $this->assertSame('review', $draft->status);
        $this->assertNull($draft->instruction);
        $this->assertSame('แผนที่ปรับแล้ว', $draft->planned_spec['title']);
        $this->assertCount(2, $draft->planned_spec['widgets']);
        $last = Http::recorded()->map(fn ($p) => $p[0])->last();
        $this->assertStringContainsString('เพิ่มการแยกตามรายการ', $last['messages'][1]['content']);
        $this->assertSame(0, Dashboard::count());
    }

    public function test_approve_requires_a_selection_and_owner(): void
    {
        $this->queue = [['title' => 'แผน', 'widgets' => [['id' => 'w1', 'type' => 'kpi', 'title' => 'จำนวน', 'measure' => ['agg' => 'count']]]]];
        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => 'สรุป']);
        $draft = ReportDraft::firstOrFail();

        $this->actingAs($this->user)->post(route('drafts.approve', $draft), [])->assertSessionHas('error');
        $this->assertSame('review', $draft->fresh()->status);

        $other = User::factory()->create();
        $this->actingAs($other)->post(route('drafts.approve', $draft), ['keep' => ['w1']])->assertForbidden();
        $this->actingAs($other)->post(route('drafts.revise', $draft), ['instruction' => 'เปลี่ยนทั้งหมด'])->assertForbidden();
    }

    public function test_brief_page_lists_observations(): void
    {
        $this->actingAs($this->user)->get(route('drafts.brief', $this->dataset))
            ->assertOk()->assertSee('ข้อสังเกตที่ระบบพบในข้อมูล')->assertSee('ร้อยละใช้จริงต่องบประมาณ');
    }
}
