<?php

/*
 * ค่าเริ่มต้นของ AI ภายในองค์กร — Admin แก้ทับได้ในหน้า "ตั้งค่า AI" (เก็บในตาราง settings)
 */
return [
    // openai = OpenAI-compatible (/chat/completions) · anthropic = Anthropic-compatible (/messages)
    'format' => env('AI_FORMAT', 'openai'),
    'base_url' => env('AI_BASE_URL'),
    // bearer = Authorization: Bearer <key> · x-api-key · api-key
    'auth_style' => env('AI_AUTH_STYLE', 'bearer'),
    'model_smart' => env('AI_MODEL_SMART'),
    'model_fast' => env('AI_MODEL_FAST'),
    'json_mode' => (bool) env('AI_JSON_MODE', false),
    'timeout' => (int) env('AI_TIMEOUT', 60),
    'verify_ssl' => (bool) env('AI_VERIFY_SSL', true),

    'max_output_tokens' => [
        // เผื่อ token ที่โมเดลใช้ "คิดก่อนตอบ" (reasoning) — ถ้าน้อยไปโมเดลจะตอบไม่ทัน
        'clarify' => 3000,
        'plan' => 6000,
        'test' => 300,
    ],
    'retries' => 2,
    'retry_delay_ms' => (int) env('AI_RETRY_DELAY_MS', 1500),
    'cache_days' => 30,
    // เก็บข้อความที่ส่ง/รับจาก AI เต็ม ๆ ในฐานข้อมูล (เฉพาะ debug — spec FR-7.4)
    'log_payload' => (bool) env('AI_LOG_PAYLOAD', false),
    // ส่งแถวตัวอย่างให้ AI กี่แถว (หลังปิดบังข้อมูลส่วนบุคคล) — spec §6.5
    'sample_rows' => 5,
];
