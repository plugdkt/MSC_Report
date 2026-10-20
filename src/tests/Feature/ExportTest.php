<?php

namespace Tests\Feature;

use App\Jobs\GenerateExportJob;
use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\Export;
use App\Models\ReportDraft;
use App\Models\User;
use App\Services\Export\ChromeRenderer;
use App\Services\Export\ExportException;
use App\Services\Export\ExportService;
use Database\Seeders\CodeSetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\BudgetWorkbook;
use Tests\TestCase;

/**
 * M4: ส่งออก PDF/ภาพ — หน้า render ลงนาม, คิวงาน, ดาวน์โหลด, สิทธิ์, ลบไฟล์หมดอายุ (ใช้ Chrome ปลอมแทนของจริง)
 */
class ExportTest extends TestCase
{
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

    private function fakeChrome(?\Closure $onPdf = null): object
    {
        $fake = new class extends ChromeRenderer
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public ?\Closure $onPdf = null;

            public function pdf(string $url, array $options = []): string
            {
                $this->calls[] = ['pdf', $url, $options];
                if ($this->onPdf) {
                    ($this->onPdf)();
                }

                return '%PDF-1.4 fake';
            }

            public function image(string $url, int $width, ?int $height, string $format, int $scale = 1, int $quality = 90): string
            {
                $this->calls[] = ['image', $url, $width, $height, $format, $scale, $quality];

                return 'IMG-'.$format;
            }
        };
        $fake->onPdf = $onPdf;
        $this->app->instance(ChromeRenderer::class, $fake);

        return $fake;
    }

    public function test_render_pages_need_a_valid_signature_and_show_the_data(): void
    {
        $version = $this->dashboard->currentVersion;
        $url = app(ExportService::class)->signedUrl('render.report', $version->id, ['o' => 'p']);

        $this->assertStringStartsWith('http://web/render/'.$version->id.'/report', $url);
        $this->get(route('render.report', $version))->assertForbidden();

        $path = $url;
        $this->get($path)->assertOk()
            ->assertSee('สารบัญ')->assertSee('บทสรุปสำหรับผู้บริหาร')->assertSee('size: A4 portrait', false)
            ->assertSee('id="app-kpis"', false)->assertSee('href="#app-kpis"', false);
        $this->get(preg_replace('/signature=[a-f0-9]+/', 'signature=tampered', $path))->assertForbidden();

        $this->get(str_replace('/report', '/dashboard', $path))->assertForbidden('ลายเซ็นของหน้ารายงานใช้กับหน้าอื่นไม่ได้');
        $tail = parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY);
        $this->assertTrue(URL::hasValidSignature(Request::create($url)));
        $this->assertFalse(URL::hasValidSignature(Request::create('http://localhost:8080'.$tail)), 'ลิงก์ที่ลงนามสำหรับ host ภายในใช้กับ host อื่นไม่ได้');
        $svc = app(ExportService::class);
        $this->get($svc->signedUrl('render.dashboard', $version->id))->assertOk()->assertSee('dash-board', false);
        $this->get($svc->signedUrl('render.infographic', $version->id))->assertOk()->assertSee('100%');
        $this->travel(10)->minutes();
        $this->get($url)->assertForbidden('ลิงก์หมดอายุใน 5 นาที');
    }

    public function test_request_validates_combinations_and_queues_a_job(): void
    {
        Queue::fake();
        $this->actingAs($this->user);

        $this->post(route('dashboards.exports.store', $this->dashboard), ['target' => 'report', 'format' => 'jpeg'])
            ->assertSessionHas('error');
        $this->post(route('dashboards.exports.store', $this->dashboard), ['target' => 'infographic', 'format' => 'pdf'])
            ->assertSessionHas('error');
        $this->post(route('dashboards.exports.store', $this->dashboard), ['target' => 'dashboard', 'format' => 'png', 'scale' => 9])
            ->assertSessionHasErrors('scale');
        $this->assertSame(0, Export::count());

        $this->post(route('dashboards.exports.store', $this->dashboard), ['target' => 'infographic', 'format' => 'png', 'scale' => 3])
            ->assertRedirect(route('exports.show', 1));
        $this->post(route('dashboards.exports.store', $this->dashboard), ['target' => 'report', 'format' => 'pdf', 'orientation' => 'portrait']);

        $png = Export::findOrFail(1);
        $this->assertSame(['scale' => 3], $png->options);
        $this->assertSame('queued', $png->status);
        $this->assertTrue($png->expires_at->isAfter(now()->addDays(6)));
        $this->assertSame(['orientation' => 'portrait'], Export::findOrFail(2)->options);
        Queue::assertPushed(GenerateExportJob::class, 2);

        $this->actingAs(User::factory()->create())
            ->post(route('dashboards.exports.store', $this->dashboard), ['target' => 'report', 'format' => 'pdf'])
            ->assertForbidden();
    }

    public function test_job_renders_through_chrome_stores_the_file_and_allows_download(): void
    {
        $chrome = $this->fakeChrome();
        $export = app(ExportService::class)->request($this->dashboard, $this->user, 'report', 'pdf', ['orientation' => 'landscape']);
        $this->assertSame('done', $export->fresh()->status, 'queue ใน test ทำงานทันที (sync)');
        $export->refresh();

        $this->assertSame('pdf', $export->format);
        $this->assertStringEndsWith('.pdf', $export->file_path);
        $this->assertSame(strlen('%PDF-1.4 fake'), $export->file_size);
        $this->assertSame($this->dashboard->title.'.pdf', $export->filename);
        Storage::disk('local')->assertExists($export->file_path);

        [$kind, $url, $options] = $chrome->calls[0];
        $this->assertSame('pdf', $kind);
        $this->assertStringContainsString('/render/'.$export->dashboard_version_id.'/report?', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertTrue($options['landscape']);
        $this->assertStringContainsString('class="pageNumber"', $options['footer']);
        $this->assertStringContainsString($this->dashboard->title, $options['footer']);

        $this->actingAs($this->user)->get(route('exports.show', $export))->assertOk()->assertSee('ดาวน์โหลดไฟล์');
        $this->actingAs($this->user)->get(route('exports.download', $export))->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeaderContains('Content-Disposition', 'attachment');

        $this->actingAs(User::factory()->create())->get(route('exports.download', $export))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('exports.download', $export))->assertOk();

        $export->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($this->user)->get(route('exports.download', $export))->assertNotFound();
        $this->actingAs($this->user)->get(route('exports.show', $export))->assertSee('หมดอายุแล้ว');
    }

    public function test_image_exports_use_the_right_sizes_and_scales(): void
    {
        $chrome = $this->fakeChrome();
        $svc = app(ExportService::class);

        $svc->request($this->dashboard, $this->user, 'infographic', 'png', ['scale' => 2]);
        $svc->request($this->dashboard, $this->user, 'dashboard', 'jpeg', []);

        $this->assertSame(['image', 1536, 1024, 'png', 2], array_slice(array_merge([$chrome->calls[0][0]], array_slice($chrome->calls[0], 2)), 0, 5));
        $this->assertStringContainsString('/infographic?', $chrome->calls[0][1]);
        $this->assertSame([1440, null, 'jpeg', 1, 90], array_slice($chrome->calls[1], 2));
        $this->assertStringContainsString('/dashboard?', $chrome->calls[1][1]);
        $this->assertStringEndsWith('-อินโฟกราฟิก.png', Export::find(1)->filename);
        $this->assertStringEndsWith('-ภาพรวม.jpg', Export::find(2)->filename);
    }

    public function test_chrome_failure_marks_export_failed_without_leaking_details_and_can_retry(): void
    {
        $this->app->instance(ChromeRenderer::class, new class extends ChromeRenderer
        {
            public function pdf(string $url, array $options = []): string
            {
                throw new \RuntimeException('secret path /usr/bin/chromium crashed');
            }
        });
        $export = app(ExportService::class)->request($this->dashboard, $this->user, 'report', 'pdf');
        $export->refresh();

        $this->assertSame('failed', $export->status);
        $this->assertNull($export->file_path);
        $this->assertStringNotContainsString('secret', $export->error_message);

        $this->app->instance(ChromeRenderer::class, new class extends ChromeRenderer
        {
            public function pdf(string $url, array $options = []): string
            {
                throw new ExportException('หน้ารายงานโหลดไม่เสร็จภายในเวลาที่กำหนด');
            }
        });
        $second = app(ExportService::class)->request($this->dashboard, $this->user, 'report', 'pdf');
        $this->assertSame('หน้ารายงานโหลดไม่เสร็จภายในเวลาที่กำหนด', $second->fresh()->error_message);

        $this->actingAs($this->user)->get(route('exports.show', $export))->assertSee('ลองสร้างใหม่');
        $this->fakeChrome();
        $this->actingAs($this->user)->post(route('exports.retry', $export))->assertRedirect(route('exports.show', $second->id + 1));
        $this->assertSame('done', Export::latest('id')->first()->status);
    }

    public function test_dashboard_page_has_export_menu_and_lists_recent_exports(): void
    {
        $this->fakeChrome();
        app(ExportService::class)->request($this->dashboard, $this->user, 'report', 'pdf');

        $this->actingAs($this->user)->get(route('dashboards.show', $this->dashboard))->assertOk()
            ->assertSee('ส่งออก')->assertSee('รายงานฉบับเต็ม')->assertSee('ไฟล์ที่ส่งออกล่าสุด');
        $this->actingAs($this->user)->get(route('dashboards.infographic', $this->dashboard))->assertOk()->assertSee('ดาวน์โหลด PNG');
    }

    public function test_purge_command_removes_expired_files_and_rows(): void
    {
        $this->fakeChrome();
        $keep = app(ExportService::class)->request($this->dashboard, $this->user, 'report', 'pdf');
        $old = app(ExportService::class)->request($this->dashboard, $this->user, 'dashboard', 'png');
        $old->update(['expires_at' => now()->subDay()]);
        $oldPath = $old->fresh()->file_path;

        $this->artisan('exports:purge')->expectsOutputToContain('1 รายการ')->assertSuccessful();

        $this->assertModelMissing($old);
        Storage::disk('local')->assertMissing($oldPath);
        $this->assertModelExists($keep);
        Storage::disk('local')->assertExists($keep->fresh()->file_path);
    }
}
