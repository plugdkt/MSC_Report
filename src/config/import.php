<?php

return [
    // กัน zip bomb ก่อนเปิดไฟล์ .xlsx
    'max_uncompressed_mb' => (int) env('IMPORT_MAX_UNCOMPRESSED_MB', 300),
    'max_zip_entries' => 2000,

    // ขนาดไฟล์สูงสุด (KB) — ต้องไม่เกิน upload_max_filesize ของ PHP และ maxAllowedContentLength ของ IIS
    'max_kb' => (int) env('IMPORT_MAX_KB', 20480),

    // จำนวนวันที่เก็บไฟล์ต้นฉบับ (spec FR-1.7)
    'retention_days' => (int) env('IMPORT_RETENTION_DAYS', 90),
];
