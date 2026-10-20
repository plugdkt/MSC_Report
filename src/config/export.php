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

    // หน้า render รับเฉพาะ IP ในช่วงนี้ (Docker network เป็น private) — ปิดด้วย false ถ้าอยู่หลัง proxy
    'render_local_only' => (bool) env('EXPORT_RENDER_LOCAL_ONLY', true),
    'render_allowed_ranges' => ['127.0.0.0/8', '::1', '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', 'fc00::/7'],

    // Chrome เข้าได้เฉพาะ host เหล่านี้ (กัน SSRF ผ่านเนื้อหาที่ผู้ใช้ใส่)
    'chrome_allowed_hosts' => ['web', 'localhost', '127.0.0.1'],

    'retention_days' => (int) env('EXPORT_RETENTION_DAYS', 7),
    'timeout' => (int) env('EXPORT_TIMEOUT', 60),
    'jpeg_quality' => 90,
    'scales' => [1, 2, 3],

    // ขนาดหน้ากระดาษ A4 ที่ 96 dpi — ความกว้างเนื้อหา (มม. ขอบ 10 → px)
    'content_width' => ['landscape' => 1047, 'portrait' => 718],
];
