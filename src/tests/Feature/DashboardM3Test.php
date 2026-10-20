<?php

namespace Tests\Feature;

use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\ReportDraft;
use App\Models\User;
use App\Services\Report\NarrativeVerifier;
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
 * M3: เวอร์ชัน/แก้ไข Dashboard, บทสรุปผู้บริหารที่ตรวจตัวเลข, ธีม, อินโฟกราฟิก
 */
class DashboardM3Test extends TestCase
{
    use AiFake;
    use RefreshDatabase;

    private User $user;

    private Dashboard $dashboard;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CodeSetSeeder::class);
        $this->user = User::factory()->create();

        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        BudgetWorkbook::create($path);
        $this->actingAs($this->user)->post(route('datasets.store'), ['file' => new UploadedFile($path, 'budget.xlsx', null, null, true)]);
        $dataset = Dataset::firstOrFail();
        $this->actingAs($this->user)->post(route('datasets.import', $dataset), ['sheet' => 'ข้อมูล', 'header_start' => 1, 'header_end' => 1]);
        $this->actingAs($this->user)->post(route('drafts.start', $dataset), ['mode' => 'rule']);
        $draft = ReportDraft::firstOrFail();
        $this->actingAs($this->user)->post(route('drafts.answer', $draft), ['answers' => ['audience' => 'executive']]);
        $this->dashboard = Dashboard::findOrFail($draft->fresh()->dashboard_id);
    }

    public function test_rule_dashboard_has_layout_rule_summary_and_infographic(): void
    {
        $v = $this->dashboard->currentVersion;

        $this->assertSame('org', $v->spec['theme']);
        foreach ($v->spec['widgets'] as $w) {
            $this->assertArrayHasKey('layout', $w);
        }
        $this->assertSame('rule', $v->executive_summary['source']);
        $this->assertNotEmpty($v->executive_summary['bullets']);

        $info = $v->computed_data['_infographic'];
        $this->assertSame(['งบประมาณใช้จริง', 'งบประมาณคงเหลือ'], array_column($info['parts'], 'label'));
        $this->assertSame(100, array_sum(array_column($info['parts'], 'pct')));
        $this->assertEquals(BudgetWorkbook::TOTAL_USED + BudgetWorkbook::TOTAL_REMAINING, $info['total']);

        $this->actingAs($this->user)->get(route('dashboards.show', $this->dashboard))->assertOk()->assertSee('บทสรุปสำหรับผู้บริหาร')->assertSee('อินโฟกราฟิก 1 หน้า');
        $this->actingAs($this->user)->get(route('dashboards.infographic', $this->dashboard))->assertOk()->assertSee('งบประมาณใช้จริง')->assertSee('100%');
        $this->actingAs($this->user)->get(route('dashboards.edit', $this->dashboard))->assertOk()->assertSee('gridstack');
    }

    public function test_editor_preview_and_save_create_versions_and_revert(): void
    {
        $spec = $this->dashboard->currentVersion->spec;
        $spec['widgets'][0]['title'] = 'จำนวนทั้งหมด (แก้)';
        $spec['widgets'][0]['layout'] = ['x' => 6, 'y' => 0, 'w' => 4, 'h' => 2];

        $this->actingAs($this->user)->postJson(route('dashboards.preview', $this->dashboard), ['spec' => $spec])
            ->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, $this->dashboard->versions()->count(), 'ดูตัวอย่างต้องไม่บันทึก');

        $this->actingAs($this->user)->putJson(route('dashboards.spec', $this->dashboard), ['spec' => $spec])
            ->assertOk()->assertJsonPath('version', 2);
        $v2 = $this->dashboard->fresh()->currentVersion;
        $this->assertSame('จำนวนทั้งหมด (แก้)', $v2->spec['widgets'][0]['title']);
        $this->assertSame(['x' => 6, 'y' => 0, 'w' => 4, 'h' => 2], $v2->spec['widgets'][0]['layout']);
        $this->assertNotNull($v2->executive_summary, 'แก้แค่หัวข้อ/ตำแหน่ง ตัวเลขไม่เปลี่ยน — คงบทสรุปเดิม');

        $bad = $spec;
        $bad['widgets'][0]['measure'] = ['column' => 'c99', 'agg' => 'sum'];
        $this->actingAs($this->user)->putJson(route('dashboards.spec', $this->dashboard), ['spec' => $bad])
            ->assertStatus(422)->assertJsonPath('ok', false);

        $v1 = $this->dashboard->versions()->where('version', 1)->first();
        $this->actingAs($this->user)->post(route('dashboards.revert', [$this->dashboard, $v1]))->assertRedirect(route('dashboards.show', $this->dashboard));
        $this->assertSame(3, $this->dashboard->fresh()->currentVersion->version);
        $this->assertSame($v1->spec['widgets'][0]['title'], $this->dashboard->fresh()->currentVersion->spec['widgets'][0]['title']);
        $this->actingAs($this->user)->get(route('dashboards.versions', $this->dashboard))->assertOk()->assertSee('ย้อนกลับไปเวอร์ชัน 1');
    }

    public function test_theme_change_and_access_control(): void
    {
        $this->actingAs($this->user)->put(route('dashboards.theme', $this->dashboard), ['theme' => 'green'])->assertSessionHas('status');
        $this->assertSame('green', $this->dashboard->fresh()->currentVersion->spec['theme']);

        $other = User::factory()->create();
        $this->actingAs($other)->get(route('dashboards.edit', $this->dashboard))->assertForbidden();
        $this->actingAs($other)->putJson(route('dashboards.spec', $this->dashboard), ['spec' => []])->assertForbidden();
        $this->actingAs($other)->get(route('dashboards.infographic', $this->dashboard))->assertForbidden();
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->get(route('dashboards.infographic', $this->dashboard))->assertOk();
        $this->actingAs($admin)->get(route('dashboards.edit', $this->dashboard))->assertForbidden();
    }

    public function test_infographic_settings_and_header_image(): void
    {
        $cols = $this->dashboard->dataset->columns->keyBy('source_name');
        $this->actingAs($this->user)->put(route('dashboards.infographic.update', $this->dashboard), [
            'title' => 'แบบรายงานทดสอบ', 'period' => 'เดือน กันยายน 2569', 'unit' => 'หน่วยงาน ทดสอบ', 'total_label' => 'งบทั้งหมด',
            'parts' => [
                ['label' => 'ใช้แล้ว', 'column' => $cols['ใช้จริง']->slug, 'color' => 'green'],
                ['label' => 'เหลือ', 'column' => $cols['คงเหลือ']->slug, 'color' => 'blue'],
                ['label' => '', 'column' => '', 'color' => 'red'],
            ],
        ])->assertSessionHas('status');
        $info = $this->dashboard->fresh()->currentVersion;
        $this->assertSame('แบบรายงานทดสอบ', $info->spec['infographic']['title']);
        $this->assertSame(['ใช้แล้ว', 'เหลือ'], array_column($info->computed_data['_infographic']['parts'], 'label'));

        $this->actingAs($this->user)->post(route('dashboards.image.upload', $this->dashboard), ['image' => UploadedFile::fake()->image('building.jpg', 800, 400)])->assertSessionHas('status');
        $path = $this->dashboard->fresh()->currentVersion->spec['infographic']['image'];
        Storage::disk('local')->assertExists($path);
        $this->actingAs($this->user)->get(route('dashboards.image', $this->dashboard))->assertOk();
        $this->actingAs(User::factory()->create())->get(route('dashboards.image', $this->dashboard))->assertForbidden();
    }

    public function test_ai_summary_keeps_only_verified_numbers(): void
    {
        $this->configureAi();
        $this->giveKey($this->user);
        $used = number_format(BudgetWorkbook::TOTAL_USED, 2);
        Http::fakeSequence(self::AI_URL)
            ->pushResponse(self::chat(['summary' => [
                ['text' => "ใช้จริงรวม {$used} บาท"],
                ['text' => 'จำนวนรายการทั้งหมด 4 รายการ'],
                ['text' => 'คาดว่าจะใช้ครบ 999,999.99 บาท ภายในเดือนหน้า'],
            ]]))
            ->pushResponse(self::chat(['summary' => [
                ['text' => "ใช้จริงรวม {$used} บาท"],
                ['text' => 'ใช้จริงคิดเป็น '.number_format(BudgetWorkbook::TOTAL_USED / 1e6, 2).' ล้านบาท'],
            ]]));

        $this->actingAs($this->user)->post(route('dashboards.summary', $this->dashboard), ['ai' => 1])->assertSessionHas('status');

        $summary = $this->dashboard->fresh()->currentVersion->executive_summary;
        $this->assertSame('ai', $summary['source']);
        $this->assertCount(2, $summary['bullets']);
        $this->assertStringNotContainsString('999,999.99', json_encode($summary, JSON_UNESCAPED_UNICODE));
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains(json_encode($r['messages'], JSON_UNESCAPED_UNICODE), '999,999.99'));
    }

    public function test_ai_summary_falls_back_to_rules_when_numbers_are_made_up(): void
    {
        $this->configureAi();
        $this->giveKey($this->user);
        Http::fake([self::AI_URL => self::chat(['summary' => [['text' => 'ยอดรวม 123,456.78 บาท'], ['text' => 'เพิ่มขึ้น 37.5%']]])]);

        $this->actingAs($this->user)->post(route('dashboards.summary', $this->dashboard), ['ai' => 1])->assertSessionHas('error');

        $this->assertSame('rule', $this->dashboard->fresh()->currentVersion->executive_summary['source']);
    }

    public function test_verifier_accepts_common_thai_number_formats(): void
    {
        $facts = [
            ['id' => 'f1', 'label' => 'ใช้จริง', 'value' => 11477076.33, 'unit' => 'บาท'],
            ['id' => 'f2', 'label' => 'ร้อยละ', 'value' => 65.31, 'unit' => '%'],
            ['id' => 'f3', 'label' => 'ต่างจากเป้า', 'value' => -0.24, 'unit' => 'จุด'],
        ];
        $result = app(NarrativeVerifier::class)->verify([
            'ใช้จริง 11,477,076.33 บาท',
            'ใช้จริง 11.48 ล้านบาท (ร้อยละ 65.3) ณ 30 ก.ย. 2569 ไตรมาส 4',
            'ต่ำกว่าเป้าหมาย 0.24 จุด',
            'ใช้จริง 12.5 ล้านบาท',
        ], $facts);

        $this->assertSame([true, true, true, false], array_column($result, 'verified'));
        $this->assertSame(['12.5 ล้าน'], $result[3]['unmatched']);
    }
}
