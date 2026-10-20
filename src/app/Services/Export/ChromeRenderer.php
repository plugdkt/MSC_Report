<?php

namespace App\Services\Export;

use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Clip;
use HeadlessChromium\Communication\Message;
use HeadlessChromium\Page;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * เปิดหน้า render ภายในด้วย Headless Chrome แล้วพิมพ์เป็น PDF หรือจับภาพ (spec FR-6, FR-6.4)
 * Chrome เปิด/ปิดใหม่ทุกงาน เพื่อไม่ให้ค้างหน่วยความจำ
 */
class ChromeRenderer
{
    private const READY_POLL_US = 200000;

    /**
     * @param  array{landscape?:bool, footer?:string}  $options
     */
    public function pdf(string $url, array $options = []): string
    {
        return $this->withPage($url, [1123, 794], function (Page $page) use ($options) {
            $response = $page->getSession()->sendMessageSync(new Message('Page.printToPDF', [
                'landscape' => $options['landscape'] ?? true,
                'printBackground' => true,
                'preferCSSPageSize' => true,
                'displayHeaderFooter' => true,
                'headerTemplate' => '<span></span>',
                'footerTemplate' => $options['footer'] ?? '<span></span>',
                'generateDocumentOutline' => true,
                'generateTaggedPDF' => true,
            ]), $this->timeoutMs());

            $data = $response->getResultData('data');
            if (! is_string($data) || $data === '') {
                throw new ExportException('Chrome ไม่ส่งข้อมูล PDF กลับมา');
            }

            return base64_decode($data);
        });
    }

    /**
     * @param  'jpeg'|'png'  $format
     * @param  int|null  $height  null = วัดความสูงจริงของหน้า (ภาพทั้งหน้า)
     */
    public function image(string $url, int $width, ?int $height, string $format, int $scale = 1, int $quality = 90): string
    {
        return $this->withPage($url, [$width, $height ?? 900], function (Page $page) use ($width, $height, $format, $scale, $quality) {
            $h = $height ?? (int) ceil((float) $page->evaluate('Math.max(document.documentElement.scrollHeight, document.body.scrollHeight)')->getReturnValue());
            $options = ['format' => $format, 'captureBeyondViewport' => true, 'clip' => new Clip(0, 0, $width, max(100, $h), (float) $scale)];
            if ($format === 'jpeg') {
                $options['quality'] = $quality;
            }
            $bytes = base64_decode($page->screenshot($options)->getBase64());
            if ($bytes === '' || $bytes === false) {
                throw new ExportException('Chrome ไม่ส่งภาพกลับมา');
            }

            return $bytes;
        });
    }

    /**
     * ให้ Chrome แปลงชื่อเฉพาะ host ภายในที่อนุญาต — ที่เหลือใช้งานไม่ได้ (ป้องกัน SSRF)
     */
    private function hostLockFlag(): string
    {
        $internal = parse_url((string) config('export.internal_url'), PHP_URL_HOST);
        $hosts = array_unique(array_filter([...config('export.chrome_allowed_hosts', []), $internal]));

        return '--host-resolver-rules=MAP * ~NOTFOUND , '.implode(' , ', array_map(fn ($h) => 'EXCLUDE '.$h, $hosts));
    }

    /**
     * @template T
     *
     * @param  array{0:int,1:int}  $window
     * @param  callable(Page): T  $work
     * @return T
     */
    private function withPage(string $url, array $window, callable $work): mixed
    {
        $profile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'msc-chrome-'.bin2hex(random_bytes(6));
        $factory = new BrowserFactory(config('export.chrome_path') ?: null);
        $browser = null;

        try {
            $browser = $factory->createBrowser([
                'headless' => true,
                'noSandbox' => (bool) config('export.no_sandbox'),
                'windowSize' => $window,
                'userDataDir' => $profile,
                'startupTimeout' => 30,
                'sendSyncDefaultTimeout' => $this->timeoutMs(),
                'customFlags' => [$this->hostLockFlag(), '--disable-gpu', '--disable-dev-shm-usage', '--hide-scrollbars', '--font-render-hinting=none', '--disable-extensions', '--no-first-run'],
            ]);
            $page = $browser->createPage();
            $page->setViewport($window[0], $window[1])->await();
            $page->navigate($url)->waitForNavigation(Page::LOAD, $this->timeoutMs());
            $this->waitUntilReady($page);

            return $work($page);
        } catch (ExportException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ExportException('สร้างไฟล์ด้วย Chrome ไม่สำเร็จ: '.mb_strimwidth($e->getMessage(), 0, 160, '…'), 0, $e);
        } finally {
            try {
                $browser?->close();
            } catch (Throwable) {
                // ปิดไม่สำเร็จก็ปล่อย — process จะถูกเก็บเมื่อจบงาน
            }
            File::deleteDirectory($profile);
        }
    }

    /**
     * รอให้หน้า render วาดกราฟและโหลดฟอนต์เสร็จ (หน้าตั้ง window.__READY__ = true)
     */
    private function waitUntilReady(Page $page): void
    {
        $deadline = microtime(true) + config('export.timeout');
        while (microtime(true) < $deadline) {
            if ($page->evaluate('window.__READY__ === true')->getReturnValue() === true) {
                return;
            }
            usleep(self::READY_POLL_US);
        }
        $error = $page->evaluate('window.__RENDER_ERROR__ || null')->getReturnValue();

        throw new ExportException('หน้ารายงานโหลดไม่เสร็จภายในเวลาที่กำหนด'.($error ? ': '.mb_strimwidth((string) $error, 0, 120, '…') : ''));
    }

    private function timeoutMs(): int
    {
        return (int) config('export.timeout') * 1000;
    }
}
