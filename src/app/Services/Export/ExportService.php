<?php

namespace App\Services\Export;

use App\Jobs\GenerateExportJob;
use App\Models\Dashboard;
use App\Models\Export;
use App\Models\User;
use App\Support\Thai;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * สร้างคำสั่งส่งออก และสร้างไฟล์จริงผ่าน Headless Chrome (spec FR-6.2: async ผ่าน queue)
 */
class ExportService
{
    public const COMBOS = [
        'report' => ['pdf'],
        'infographic' => ['jpeg', 'png'],
        'dashboard' => ['jpeg', 'png'],
    ];

    public const INFOGRAPHIC_SIZE = [1536, 1024];

    public const DASHBOARD_WIDTH = 1440;

    public function __construct(private readonly ChromeRenderer $renderer) {}

    /**
     * @param  array{scale?:int, orientation?:string}  $options
     */
    public function request(Dashboard $dashboard, User $user, string $target, string $format, array $options = []): Export
    {
        if (! in_array($format, self::COMBOS[$target] ?? [], true)) {
            throw new ExportException('รูปแบบไฟล์นี้ไม่รองรับกับส่วนที่เลือก');
        }
        $version = $dashboard->currentVersion ?? throw new ExportException('ไม่พบเวอร์ชันของรายงาน');
        if ($target === 'infographic' && empty($version->spec['infographic']['enabled'])) {
            throw new ExportException('รายงานนี้ยังไม่ได้ตั้งค่าอินโฟกราฟิก');
        }

        $scale = (int) ($options['scale'] ?? ($target === 'infographic' ? 2 : 1));
        $clean = $format === 'pdf'
            ? ['orientation' => ($options['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape']
            : ['scale' => in_array($scale, config('export.scales'), true) ? $scale : 1];

        $export = Export::create([
            'dashboard_id' => $dashboard->id,
            'dashboard_version_id' => $version->id,
            'user_id' => $user->id,
            'format' => $format,
            'target' => $target,
            'options' => $clean,
            'status' => 'queued',
            'expires_at' => now()->addDays(config('export.retention_days')),
        ]);
        GenerateExportJob::dispatch($export->id);

        return $export;
    }

    public function run(Export $export): void
    {
        $started = hrtime(true);
        $export->update(['status' => 'processing', 'error_message' => null]);

        try {
            $bytes = $this->render($export);
            $ext = $export->format === 'jpeg' ? 'jpg' : $export->format;
            $path = 'exports/'.$export->user_id.'/'.Str::uuid().'.'.$ext;
            Storage::disk('local')->put($path, $bytes);

            $export->update([
                'status' => 'done',
                'file_path' => $path,
                'filename' => $this->filename($export, $ext),
                'file_size' => strlen($bytes),
                'duration_ms' => (int) ((hrtime(true) - $started) / 1e6),
            ]);
        } catch (Throwable $e) {
            $message = $e instanceof ExportException ? $e->getMessage() : 'สร้างไฟล์ไม่สำเร็จ กรุณาลองใหม่หรือติดต่อผู้ดูแลระบบ';
            if (! $e instanceof ExportException || $e->getPrevious()) {
                Log::error('Export failed', ['export' => $export->id, 'error' => $e->getMessage(), 'previous' => $e->getPrevious()?->getMessage()]);
            }
            $export->update(['status' => 'failed', 'error_message' => $message, 'duration_ms' => (int) ((hrtime(true) - $started) / 1e6)]);
        }
    }

    private function render(Export $export): string
    {
        $version = $export->version;
        $landscape = ($export->options['orientation'] ?? 'landscape') === 'landscape';

        if ($export->format === 'pdf') {
            $url = $this->signedUrl('render.report', $version->id, ['o' => $landscape ? 'l' : 'p']);

            return $this->renderer->pdf($url, ['landscape' => $landscape, 'footer' => $this->footer($export)]);
        }

        $scale = (int) ($export->options['scale'] ?? 1);
        $quality = (int) config('export.jpeg_quality');

        if ($export->target === 'infographic') {
            [$w, $h] = self::INFOGRAPHIC_SIZE;

            return $this->renderer->image($this->signedUrl('render.infographic', $version->id), $w, $h, $export->format, $scale, $quality);
        }

        return $this->renderer->image($this->signedUrl('render.dashboard', $version->id), self::DASHBOARD_WIDTH, null, $export->format, $scale, $quality);
    }

    /**
     * ลิงก์ลงนามอายุสั้น สำหรับให้ Chrome (ภายในเครื่อง) เปิดหน้า render โดยไม่ต้องล็อกอิน
     *
     * @param  array<string, string>  $query
     */
    public function signedUrl(string $route, int $versionId, array $query = []): string
    {
        URL::forceRootUrl(rtrim((string) config('export.internal_url'), '/'));
        try {
            return URL::temporarySignedRoute($route, now()->addMinutes(5), ['version' => $versionId] + $query);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    private function footer(Export $export): string
    {
        $title = e($export->dashboard->title);

        return '<div style="width:100%;padding:0 11mm;font-size:9px;font-family:Loma,Garuda,Tahoma,sans-serif;color:#555;display:flex;justify-content:space-between">'
            .'<span>'.$title.' · ออกรายงาน '.e(Thai::date(now())).'</span>'
            .'<span>หน้า <span class="pageNumber"></span> / <span class="totalPages"></span></span></div>';
    }

    private function filename(Export $export, string $ext): string
    {
        $title = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]+/u', ' ', $export->dashboard->title) ?? 'report');
        $suffix = match ($export->target) {
            'infographic' => '-อินโฟกราฟิก',
            'dashboard' => '-ภาพรวม',
            default => '',
        };

        return mb_substr($title, 0, 80).$suffix.'.'.$ext;
    }
}
