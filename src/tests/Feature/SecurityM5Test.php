<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Dashboard;
use App\Models\Dataset;
use App\Models\Export;
use App\Models\ReportDraft;
use App\Models\Setting;
use App\Models\Share;
use App\Models\User;
use App\Models\UserAiCredential;
use App\Services\Export\ExportService;
use App\Services\Import\ImportException;
use App\Services\Import\SpreadsheetReader;
use Database\Seeders\CodeSetSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BudgetWorkbook;
use Tests\TestCase;

/**
 * M5: แชร์, สิทธิ์ (IDOR), audit log, Admin, ส่วนหัวความปลอดภัย, ความยินยอม AI, ลบต่อเนื่อง, หมุน key, กัน zip bomb
 */
class SecurityM5Test extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $viewer;

    private User $stranger;

    private User $admin;

    private Dataset $dataset;

    private Dashboard $dashboard;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seed(CodeSetSeeder::class);
        $this->owner = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->admin = User::factory()->admin()->create();

        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        BudgetWorkbook::create($path);
        $this->actingAs($this->owner)->post(route('datasets.store'), ['file' => new UploadedFile($path, 'budget.xlsx', null, null, true)]);
        $this->dataset = Dataset::firstOrFail();
        $this->actingAs($this->owner)->post(route('datasets.import', $this->dataset), ['sheet' => 'ข้อมูล', 'header_start' => 1, 'header_end' => 1]);
        $this->actingAs($this->owner)->post(route('drafts.start', $this->dataset), ['mode' => 'rule']);
        $draft = ReportDraft::firstOrFail();
        $this->actingAs($this->owner)->post(route('drafts.answer', $draft), ['answers' => ['audience' => 'executive']]);
        $this->dashboard = Dashboard::findOrFail($draft->fresh()->dashboard_id);
    }

    public function test_share_lets_viewer_view_and_export_but_not_edit(): void
    {
        $this->actingAs($this->viewer)->get(route('dashboards.show', $this->dashboard))->assertForbidden();

        $this->actingAs($this->owner)->post(route('dashboards.shares.store', $this->dashboard), ['person' => $this->viewer->username.' — '.$this->viewer->name])
            ->assertSessionHas('status');
        $this->assertDatabaseHas('shares', ['dashboard_id' => $this->dashboard->id, 'user_id' => $this->viewer->id]);

        $this->actingAs($this->viewer)->get(route('dashboards.show', $this->dashboard))->assertOk()->assertSee('แก้ไขไม่ได้');
        $this->actingAs($this->viewer)->get(route('home'))->assertOk()->assertSee('แชร์ให้ฉัน')->assertSee($this->dashboard->title);
        $this->actingAs($this->viewer)->get(route('dashboards.edit', $this->dashboard))->assertForbidden();
        $this->actingAs($this->viewer)->put(route('dashboards.theme', $this->dashboard), ['theme' => 'org'])->assertForbidden();
        $this->actingAs($this->viewer)->post(route('dashboards.shares.store', $this->dashboard), ['person' => $this->stranger->username])->assertForbidden();
        $this->actingAs($this->viewer)->post(route('dashboards.exports.store', $this->dashboard), ['target' => 'report', 'format' => 'pdf'])->assertRedirect();
        $this->assertDatabaseHas('exports', ['dashboard_id' => $this->dashboard->id, 'user_id' => $this->viewer->id]);

        $this->actingAs($this->stranger)->get(route('dashboards.show', $this->dashboard))->assertForbidden();

        $share = Share::firstOrFail();
        $this->actingAs($this->owner)->delete(route('dashboards.shares.destroy', [$this->dashboard, $share]))->assertSessionHas('status');
        $this->actingAs($this->viewer)->get(route('dashboards.show', $this->dashboard))->assertForbidden();
    }

    public function test_share_rejects_self_unknown_and_inactive_users(): void
    {
        $inactive = User::factory()->inactive()->create();
        foreach ([$this->owner->username, 'nobody.here', $inactive->username] as $person) {
            $this->actingAs($this->owner)->post(route('dashboards.shares.store', $this->dashboard), ['person' => $person])->assertSessionHas('error');
        }
        $this->assertSame(0, Share::count());
    }

    public function test_user_search_needs_two_characters_and_hides_inactive(): void
    {
        $this->actingAs($this->owner)->getJson(route('users.search', ['q' => 'a']))->assertOk()->assertExactJson([]);
        $inactive = User::factory()->inactive()->create(['username' => 'zzinactive']);
        $this->actingAs($this->owner)->getJson(route('users.search', ['q' => 'zzinac']))->assertOk()->assertExactJson([]);
        $this->actingAs($this->owner)->getJson(route('users.search', ['q' => substr($this->viewer->username, 0, 3)]))->assertOk()->assertJsonPath('0.value', fn ($v) => str_starts_with($v, $this->viewer->username));
        $this->assertNotNull($inactive->id);
    }

    public function test_idor_matrix_for_other_users_resources(): void
    {
        $draft = ReportDraft::firstOrFail();
        $export = Export::create(['dashboard_id' => $this->dashboard->id, 'dashboard_version_id' => $this->dashboard->current_version_id, 'user_id' => $this->owner->id, 'format' => 'pdf', 'target' => 'report', 'status' => 'done', 'file_path' => 'exports/x.pdf', 'filename' => 'x.pdf', 'expires_at' => now()->addDay()]);
        Storage::disk('local')->put('exports/x.pdf', '%PDF');

        $as = fn (User $u) => $this->actingAs($u);
        foreach ([$this->stranger, $this->viewer] as $u) {
            $as($u)->get(route('datasets.show', $this->dataset))->assertForbidden();
            $as($u)->delete(route('datasets.destroy', $this->dataset))->assertForbidden();
            $as($u)->put(route('datasets.columns.update', $this->dataset), [])->assertForbidden();
            $as($u)->get(route('drafts.show', $draft))->assertForbidden();
            $as($u)->post(route('drafts.start', $this->dataset), ['mode' => 'rule'])->assertForbidden();
            $as($u)->get(route('dashboards.show', $this->dashboard))->assertForbidden();
            $as($u)->get(route('dashboards.versions', $this->dashboard))->assertForbidden();
            $as($u)->get(route('dashboards.infographic', $this->dashboard))->assertForbidden();
            $as($u)->post(route('dashboards.exports.store', $this->dashboard), ['target' => 'report', 'format' => 'pdf'])->assertForbidden();
            $as($u)->get(route('exports.show', $export))->assertForbidden();
            $as($u)->get(route('exports.download', $export))->assertForbidden();
            $as($u)->post(route('exports.retry', $export))->assertForbidden();
        }
        $as($this->owner)->get(route('exports.download', $export))->assertOk();
    }

    public function test_admin_pages_are_admin_only(): void
    {
        foreach (['admin.overview', 'admin.audit.index', 'admin.settings.edit', 'admin.users.index'] as $name) {
            $this->actingAs($this->owner)->get(route($name))->assertForbidden();
            $this->actingAs($this->admin)->get(route($name))->assertOk();
        }
        $this->actingAs($this->owner)->put(route('admin.settings.update'), ['org_name' => 'x', 'default_theme' => 'org'])->assertForbidden();
    }

    public function test_audit_log_records_key_events_without_secrets(): void
    {
        $this->assertDatabaseHas('audit_logs', ['action' => 'dataset.upload', 'username' => $this->owner->username]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'dataset.import']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.created']);

        $this->actingAs($this->owner)->post(route('dashboards.shares.store', $this->dashboard), ['person' => $this->viewer->username]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'share.added']);

        $this->actingAs($this->admin)->put(route('admin.users.update', $this->viewer), ['role' => 'admin', 'is_active' => 1]);
        $log = AuditLog::where('action', 'admin.user_updated')->firstOrFail();
        $this->assertSame($this->admin->username, $log->username);
        $this->assertSame('staff → admin', $log->meta['role']);

        $this->actingAs($this->admin)->put(route('admin.ai.update'), [
            'format' => 'openai', 'base_url' => 'https://ai.example/v1', 'auth_style' => 'bearer', 'timeout' => 30,
            'shared_key' => 'sk-super-secret-value', 'shared_monthly_cap' => 0,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.settings']);
        $this->assertStringNotContainsString('sk-super-secret-value', json_encode(AuditLog::all()->toArray()));
    }

    public function test_audit_viewer_filters_and_paginates(): void
    {
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['action' => 'dataset.upload']))
            ->assertOk()->assertSee($this->owner->username)->assertViewHas('logs', fn ($logs) => $logs->count() > 0 && $logs->every(fn ($l) => $l->action === 'dataset.upload'));
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['q' => 'ไม่มีทางเจอ-xyz']))->assertOk()->assertSee('ไม่พบกิจกรรม');
        $this->actingAs($this->admin)->get(route('admin.audit.index', ['from' => 'not-a-date']))->assertSessionHasErrors('from');
    }

    public function test_org_settings_are_used_in_footer(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['org_name' => 'หน่วยงานทดสอบ', 'default_theme' => 'org'])->assertSessionHas('status');
        $this->actingAs($this->owner)->get(route('home'))->assertSee('หน่วยงานทดสอบ');
        $this->actingAs($this->admin)->put(route('admin.settings.update'), ['org_name' => 'x', 'default_theme' => 'nope'])->assertSessionHasErrors('default_theme');
    }

    public function test_security_headers_are_sent(): void
    {
        $response = $this->actingAs($this->owner)->get(route('home'));
        $response->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->assertStringContainsString("frame-ancestors 'self'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy'));
        $this->get(route('landing'))->assertHeader('Referrer-Policy');
        $this->assertStringNotContainsString('googleapis', $this->get(route('landing'))->getContent());
    }

    public function test_first_ai_use_requires_consent_and_can_skip_samples(): void
    {
        $this->assertNull($this->owner->fresh()->ai_consent_at);
        $before = ReportDraft::count();

        $this->actingAs($this->owner)->post(route('drafts.start', $this->dataset), ['mode' => 'ai'])
            ->assertOk()->assertSee('ก่อนให้ AI ช่วยสรุป');
        $this->assertSame($before, ReportDraft::count());

        $this->actingAs($this->owner)->post(route('drafts.start', $this->dataset), ['mode' => 'ai', 'consent' => 1, 'send_samples' => 0])->assertRedirect();
        $this->assertNotNull($this->owner->fresh()->ai_consent_at);
        $this->assertFalse(ReportDraft::latest('id')->first()->send_samples);
        $this->assertDatabaseHas('audit_logs', ['action' => 'ai.consent']);

        // ยินยอมแล้ว — ครั้งถัดไปไม่ถามซ้ำ
        $this->actingAs($this->owner)->post(route('drafts.start', $this->dataset), ['mode' => 'ai'])->assertRedirect();
        $this->assertTrue(ReportDraft::latest('id')->first()->send_samples);
    }

    public function test_deleting_dataset_removes_reports_exports_and_files(): void
    {
        $disk = Storage::disk('local');
        $disk->put('exports/a.pdf', '%PDF');
        Export::create(['dashboard_id' => $this->dashboard->id, 'dashboard_version_id' => $this->dashboard->current_version_id, 'user_id' => $this->owner->id, 'format' => 'pdf', 'target' => 'report', 'status' => 'done', 'file_path' => 'exports/a.pdf', 'filename' => 'a.pdf', 'expires_at' => now()->addDay()]);
        $disk->put('dashboards/'.$this->dashboard->id.'/logo.png', 'x');
        $stored = $this->dataset->stored_path;
        $this->assertTrue($disk->exists($stored));

        $this->actingAs($this->owner)->delete(route('datasets.destroy', $this->dataset))->assertRedirect(route('home'));

        $this->assertSame(0, Dataset::count());
        $this->assertSame(0, Dashboard::count());
        $this->assertSame(0, Export::count());
        $this->assertSame(0, ReportDraft::count());
        $this->assertFalse($disk->exists($stored));
        $this->assertFalse($disk->exists('exports/a.pdf'));
        $this->assertFalse($disk->exists('dashboards/'.$this->dashboard->id.'/logo.png'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'dataset.deleted']);
    }

    public function test_render_pages_reject_non_internal_addresses(): void
    {
        $url = app(ExportService::class)->signedUrl('render.report', $this->dashboard->current_version_id, ['o' => 'p']);

        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get($url)->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.5'])->get($url)->assertOk();

        config(['export.render_local_only' => false]);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])->get($url)->assertOk();
    }

    public function test_rotate_command_reencrypts_keys_with_new_app_key(): void
    {
        $oldRaw = random_bytes(32);
        $old = new Encrypter($oldRaw, config('app.cipher'));
        $credential = UserAiCredential::create(['user_id' => $this->owner->id, 'encrypted_key' => $old->encryptString('sk-user-key'), 'key_last4' => '-key', 'key_fingerprint' => 'f', 'status' => 'active']);
        Setting::create(['key' => 'ai.shared_key', 'value' => $old->encryptString('sk-shared'), 'is_encrypted' => true]);

        Artisan::call('ai-keys:rotate', ['--old-key' => 'base64:'.base64_encode($oldRaw), '--dry-run' => true]);
        $this->assertSame($old->encryptString('sk-user-key') === $credential->fresh()->getRawOriginal('encrypted_key'), false);
        $this->assertSame('sk-user-key', $old->decryptString($credential->fresh()->getRawOriginal('encrypted_key')), 'dry-run ต้องไม่เปลี่ยนข้อมูล');

        $this->assertSame(0, Artisan::call('ai-keys:rotate', ['--old-key' => 'base64:'.base64_encode($oldRaw)]));
        $this->assertSame('sk-user-key', Crypt::decryptString($credential->fresh()->getRawOriginal('encrypted_key')));
        $this->assertSame('sk-shared', Setting::get('ai.shared_key'));

        $this->assertSame(1, Artisan::call('ai-keys:rotate', ['--old-key' => 'base64:'.base64_encode(random_bytes(32))]));
        $this->assertSame(1, Artisan::call('ai-keys:rotate'));
    }

    public function test_zip_bomb_guard_rejects_oversized_archives(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        BudgetWorkbook::create($path);
        $reader = app(SpreadsheetReader::class);
        $this->assertNotEmpty($reader->readAll($path));

        config(['import.max_zip_entries' => 2]);
        $this->expectException(ImportException::class);
        $reader->readAll($path);
    }

    public function test_doctor_command_runs(): void
    {
        Artisan::call('app:doctor');
        $this->assertStringContainsString('เชื่อมต่อฐานข้อมูล', Artisan::output());
    }
}
