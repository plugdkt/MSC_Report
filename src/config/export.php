<?php

/*
 * ส่งออก PDF/ภาพด้วย Headless Chrome (spec FR-6, §9)
 */
return [
    // path ของ Chrome/Chromium — Docker: /usr/bin/chromium · IIS: C:\Program Files\Google\Chrome\Application\chrome.exe
    'chrome_path' => env('CHROME_PATH'),

    // ต้องเปิดเมื่อรันเป็น root ใน container; บน IIS (Windows) ปิดได้
    'no_sandbox' => (bool) env('CHROME_NO_SANDBOX', true),

    // URL ที่ Chrome ใช้เปิดหน้า render ภายในเครื่อง (ไม่ผ่านอินเทอร์เน็ต) — Docker: http://web · IIS: http://localhost
    'internal_url' => env('EXPORT_INTERNAL_URL', 'http://web'),

    'retention_days' => (int) env('EXPORT_RETENTION_DAYS', 7),
    'timeout' => (int) env('EXPORT_TIMEOUT', 60),
    'jpeg_quality' => 90,
    'scales' => [1, 2, 3],

    // ขนาดหน้ากระดาษ A4 ที่ 96 dpi — ความกว้างเนื้อหา (มม. ขอบ 10 → px)
    'content_width' => ['landscape' => 1047, 'portrait' => 718],
];
