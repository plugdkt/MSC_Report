<?php

namespace Tests\Feature;

use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\ReportDraft;
use App\Models\User;
use App\Services\Export\ExportService;
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
 * M6: อินโฟกราฟิกแบบเล่าเรื่อง — บล็อกอ้าง widget, ขนาดเลือกได้, ตัวเลขมาจากระบบ
 */
class StoryInfographicTest extends TestCase
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
     * @param  array<string, mixed>|null  $infographic
     * @return array<string, mixed>
     */
    private function plan(?array $infographic): array
    {
        $used = $this->slug('ใช้จริง');
        $spec = ['title' => 'สรุปตามที่ขอ', 'widgets' => [
            ['id' => 'w1', 'type' => 'kpi', 'title' => 'ใช้จริงรวม', 'measure' => ['column' => $used, 'agg' => 'sum']],
            ['id' => 'w2', 'type' => 'kpi', 'title' => 'จำนวนรายการ', 'measure' => ['agg' => 'count']],
            ['id' => 'w3', 'type' => 'bar', 'title' => 'ใช้จริงตามหมวด', 'dimension' => ['column' => $this->slug('คำอธิบาย')], 'measures' => [['column' => $used, 'agg' => 'sum']]],
            ['id' => 'w4', 'type' => 'pie', 'title' => 'สัดส่วนตามหมวด', 'dimension' => ['column' => $this->slug('คำอธิบาย')], 'measure' => ['column' => $used, 'agg' => 'sum']],
        ]];
        if ($infographic) {
            $spec['infographic'] = $infographic;
        }

        return $spec;
    }

    private function create(?array $infographic): Dashboard
    {
        $spec = $this->plan($infographic);
        Http::fake([self::AI_URL => fn (Request $r) => str_contains($r['messages'][0]['content'] ?? '', 'บทสรุปสำหรับผู้บริหาร') ? self::chat(['summary' => []]) : self::chat($spec)]);
        $this->actingAs($this->user)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'brief' => 'สรุปให้หน่อย'])->assertRedirect();

        return Dashboard::findOrFail(ReportDraft::firstOrFail()->dashboard_id);
    }

    public function test_ai_designed_story_is_validated_and_numbers_come_from_system(): void
    {
        $dashboard = $this->create([
            'size' => 'square', 'title' => 'สรุปตามที่ขอ', 'subtitle' => 'ทดสอบ',
            'blocks' => [
                ['type' => 'kpis', 'refs' => ['w1', 'w2', 'nope']],
                ['type' => 'ring', 'ref' => 'w4', 'title' => 'สัดส่วน'],
                ['type' => 'bars', 'ref' => 'w1'],            // kpi ใช้เป็นแท่งไม่ได้ → ถูกข้าม
                ['type' => 'bars', 'ref' => 'w3', 'limit' => 99],
                ['type' => 'explode', 'ref' => 'w3'],         // ชนิดไม่รู้จัก → ถูกข้าม
                ['type' => 'highlights'],
                ['type' => 'text', 'text' => '   '],          // ข้อความว่าง → ถูกข้าม
            ],
        ]);

        $g = $dashboard->currentVersion->spec['infographic'];
        $this->assertSame('story', $g['mode']);
        $this->assertTrue($g['enabled']);
        $this->assertSame('square', $g['size']);
        $this->assertSame(['kpis', 'ring', 'bars', 'highlights'], array_column($g['blocks'], 'type'));
        $this->assertSame(['w1', 'w2'], $g['blocks'][0]['refs']);
        $this->assertSame(12, $g['blocks'][2]['limit']);

        $computed = $dashboard->currentVersion->computed_data['_infographic'];
        $this->assertSame('story', $computed['mode']);
        $this->assertEquals(BudgetWorkbook::TOTAL_USED, $computed['blocks'][0]['items'][0]['value']);
        $this->assertSame(['1.1 ค่าจ้างชั่วคราว', '2.1.2 ค่าใช้สอย'], $computed['blocks'][2]['data']['categories']);
    }

    public function test_invalid_ai_infographic_falls_back_to_system_composition(): void
    {
        $dashboard = $this->create(['size' => 'huge', 'blocks' => [['type' => 'ring', 'ref' => 'w99']]]);

        $g = $dashboard->currentVersion->spec['infographic'];
        $this->assertTrue($g['enabled']);
        $this->assertContains($g['mode'], ['story', 'parts']);
    }

    public function test_story_page_and_render_page_show_blocks(): void
    {
        $dashboard = $this->create(['size' => 'a4l', 'blocks' => [['type' => 'kpis', 'refs' => ['w1']], ['type' => 'bars', 'ref' => 'w3', 'title' => 'จัดอันดับหมวด'], ['type' => 'ring', 'ref' => 'w4']]]);

        $this->actingAs($this->user)->get(route('dashboards.infographic', $dashboard))
            ->assertOk()->assertSee('ig2-block', false)->assertSee('จัดอันดับหมวด')->assertSee('1.1 ค่าจ้างชั่วคราว')->assertSee('247,700.50');

        $url = app(ExportService::class)->signedUrl('render.infographic', $dashboard->current_version_id);
        $this->get($url)->assertOk()->assertSee('ig2-grid', false)->assertSee('MSC_IG2', false);

        $this->assertSame([1754, null], ExportService::infographicSize($dashboard->currentVersion->spec['infographic']));
        $this->assertSame([1536, 1024], ExportService::infographicSize(['mode' => 'parts']));
    }

    public function test_user_can_edit_blocks_and_regenerate_automatically(): void
    {
        $dashboard = $this->create(null);
        $before = $dashboard->currentVersion->version;

        $this->actingAs($this->user)->put(route('dashboards.infographic.update', $dashboard), [
            'mode' => 'story', 'size' => 'long', 'title' => 'ภาพสรุป', 'subtitle' => 'ทดสอบ',
            'blocks' => [
                ['type' => 'kpis'],
                ['type' => 'list', 'ref' => 'w3', 'title' => 'ลำดับ'],
                ['type' => 'text', 'text' => 'หมายเหตุสำคัญ'],
                ['type' => ''],
            ],
        ])->assertSessionHas('status');

        $dashboard->refresh();
        $g = $dashboard->currentVersion->spec['infographic'];
        $this->assertSame($before + 1, $dashboard->currentVersion->version);
        $this->assertSame('long', $g['size']);
        $this->assertSame(['kpis', 'list', 'text'], array_column($g['blocks'], 'type'));
        $this->assertSame(['w1', 'w2'], $g['blocks'][0]['refs']);

        $this->actingAs($this->user)->put(route('dashboards.infographic.update', $dashboard), ['mode' => 'story', 'size' => 'a4p', 'blocks' => [['type' => 'ring', 'ref' => 'w99']]])
            ->assertSessionHas('error');

        $this->actingAs($this->user)->put(route('dashboards.infographic.update', $dashboard), ['mode' => 'auto'])->assertSessionHas('status');
        $auto = $dashboard->fresh()->currentVersion->spec['infographic'];
        $this->assertSame('story', $auto['mode']);
        $this->assertContains('highlights', array_column($auto['blocks'], 'type'));
        $this->assertContains('ring', array_column($auto['blocks'], 'type'));
    }

    public function test_viewer_cannot_edit_story_infographic(): void
    {
        $dashboard = $this->create(null);
        $other = User::factory()->create();
        $this->actingAs($other)->put(route('dashboards.infographic.update', $dashboard), ['mode' => 'auto'])->assertForbidden();
    }

    public function test_parts_mode_still_works_for_budget_style_reports(): void
    {
        $dashboard = $this->create(null);
        $used = $this->slug('ใช้จริง');
        $budget = $this->slug('งบประมาณ');

        $this->actingAs($this->user)->put(route('dashboards.infographic.update', $dashboard), [
            'mode' => 'parts', 'title' => 'รายงานงบ', 'total_label' => 'งบทั้งหมด',
            'parts' => [['label' => 'ใช้จริง', 'column' => $used, 'color' => 'green'], ['label' => 'งบประมาณ', 'column' => $budget, 'color' => 'blue']],
        ])->assertSessionHas('status');

        $g = $dashboard->fresh()->currentVersion->spec['infographic'];
        $this->assertSame('parts', $g['mode']);
        $this->actingAs($this->user)->get(route('dashboards.infographic', $dashboard))->assertOk()->assertSee('ig-canvas', false);
    }
}
