<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * หน้า render (ใช้เฉพาะ Headless Chrome ในเครื่อง) รับเฉพาะ IP ภายใน — ชั้นป้องกันเพิ่มจากลิงก์ลงนาม
 * ปิดได้ด้วย EXPORT_RENDER_LOCAL_ONLY=false (กรณีอยู่หลัง reverse proxy ที่ทำให้ IP ผู้เรียกเป็นเครื่องเดียวกันอยู่แล้ว)
 */
class RenderLocalOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('export.render_local_only') && ! IpUtils::checkIp((string) $request->ip(), config('export.render_allowed_ranges'))) {
            abort(403, 'หน้านี้ใช้ภายในเครื่องเท่านั้น');
        }

        return $next($request);
    }
}
