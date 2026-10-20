<?php

namespace Tests\Feature;

use App\Services\Export\ChromeRenderer;
use App\Services\Export\ExportException;
use Tests\TestCase;

/**
 * ทดสอบ Headless Chrome จริง (ข้ามเองถ้าไม่มี Chrome ในเครื่อง) — ใช้ไฟล์ HTML ชั่วคราวแทนหน้า render
 */
class ChromeRendererTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $chrome = config('export.chrome_path') ?: env('CHROME_PATH');
        if (! $chrome || ! is_file($chrome)) {
            $this->markTestSkipped('ไม่พบ Chrome/Chromium (ตั้ง CHROME_PATH)');
        }
        config(['export.chrome_path' => $chrome, 'export.timeout' => 25]);
        $this->dir = sys_get_temp_dir().'/chrome-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            array_map('unlink', glob($this->dir.'/*') ?: []);
            @rmdir($this->dir);
        }
        parent::tearDown();
    }

    private function page(string $body, bool $ready = true): string
    {
        $file = $this->dir.'/page.html';
        file_put_contents($file, '<!doctype html><html lang="th"><meta charset="utf-8"><title>ทดสอบ</title><style>@page{size:A4 landscape;margin:10mm} body{font-family:"Loma",sans-serif;margin:0} .box{width:400px;height:200px;background:#1f3b6e}</style><body>'
            .$body.($ready ? '<script>window.__READY__ = true;</script>' : '').'</body></html>');

        return 'file://'.$file;
    }

    public function test_image_has_requested_size_and_scale(): void
    {
        $url = $this->page('<div class="box"></div>');
        $renderer = new ChromeRenderer;

        $png = $renderer->image($url, 600, 300, 'png', 1);
        $this->assertSame("\x89PNG", substr($png, 0, 4));
        $this->assertSame([600, 300], array_values(unpack('N2', substr($png, 16, 8))));

        $png2 = $renderer->image($url, 600, 300, 'png', 2);
        $this->assertSame([1200, 600], array_values(unpack('N2', substr($png2, 16, 8))));

        $jpg = $renderer->image($url, 600, 300, 'jpeg', 1, 80);
        $this->assertSame("\xFF\xD8", substr($jpg, 0, 2));
    }

    public function test_full_page_height_is_measured(): void
    {
        $url = $this->page(str_repeat('<div class="box"></div>', 5));
        $png = (new ChromeRenderer)->image($url, 500, null, 'png', 1);

        [$w, $h] = array_values(unpack('N2', substr($png, 16, 8)));
        $this->assertSame(500, $w);
        $this->assertGreaterThanOrEqual(1000, $h);
    }

    public function test_pdf_has_outline_links_text_and_footer(): void
    {
        $url = $this->page('<h1 id="a">บทที่หนึ่ง</h1><p><a href="#b">ไปบทที่สอง</a></p><div class="box"></div><h1 id="b" style="break-before:page">บทที่สอง</h1><h2>หัวข้อย่อย</h2>');
        $pdf = (new ChromeRenderer)->pdf($url, ['landscape' => true, 'footer' => '<div style="font-size:9px">หน้า <span class="pageNumber"></span>/<span class="totalPages"></span></div>']);

        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertSame(2, preg_match_all('/\/Type\s*\/Page[^s]/', $pdf), 'ต้องมี 2 หน้า');
        $this->assertStringContainsString('/Outlines', $pdf);
        $this->assertGreaterThanOrEqual(1, preg_match_all('/\/Subtype\s*\/Link/', $pdf));

        if (trim((string) shell_exec('command -v pdftotext')) !== '') {
            $file = $this->dir.'/out.pdf';
            file_put_contents($file, $pdf);
            $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($file).' -');
            $this->assertStringContainsString('บทที่หนึ่ง', $text, 'ข้อความไทยต้องค้นหา/คัดลอกได้');
            $this->assertStringContainsString('หน้า 1/2', preg_replace('/\s+/u', ' ', $text));
        }
    }

    public function test_page_that_never_becomes_ready_fails_with_clear_message(): void
    {
        config(['export.timeout' => 3]);

        $this->expectException(ExportException::class);
        $this->expectExceptionMessage('โหลดไม่เสร็จภายในเวลาที่กำหนด');
        (new ChromeRenderer)->image($this->page('<p>x</p>', ready: false), 400, 300, 'png');
    }
}
