@extends('layouts.app')

@section('title', 'ไฟล์ Excel แนะนำ')

@section('body')
<main>
    <div class="container">
        <div class="page-head">
            <div>
                <h1>ไฟล์ Excel แนะนำ</h1>
                <p class="muted" style="margin:.2rem 0 0;max-width:46rem">ดาวน์โหลดไปกรอกข้อมูล แล้วอัปโหลดกลับมา ระบบจะรู้จักทุกคอลัมน์ทันทีโดยไม่ต้องเดา — มีรายการให้เลือก (dropdown) และตรวจรูปแบบตัวเลข/วันที่ให้ตั้งแต่ตอนกรอก</p>
            </div>
        </div>

        <div class="grid-3">
            @foreach ($templates as $key => $t)
                <div class="card" style="display:flex;flex-direction:column;gap:.6rem">
                    <h2 style="font-size:1.2rem;margin:0">{{ $t['name'] }}</h2>
                    <p class="muted" style="margin:0;flex:1">{{ $t['description'] }}</p>
                    <details>
                        <summary class="muted" style="cursor:pointer">คอลัมน์ ({{ count($t['fields']) }})</summary>
                        <ul style="margin:.4rem 0 0;padding-left:1.2rem;font-size:.92rem">
                            @foreach ($t['fields'] as $f)
                                <li>{{ $f['label'] }}@if ($f['required'] ?? false) <span style="color:var(--warn)">*</span>@endif @if (! empty($f['options']) || ! empty($f['options_from'])) <span class="muted">(มีรายการให้เลือก)</span>@endif</li>
                            @endforeach
                        </ul>
                    </details>
                    <a class="btn btn-primary" href="{{ route('excel-templates.download', $key) }}">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 4v12M6 10l6 6 6-6M4 20h16"/></svg>
                        ดาวน์โหลด .xlsx
                    </a>
                </div>
            @endforeach
        </div>

        <p class="muted" style="margin-top:1.4rem">มีไฟล์ที่นำเข้าแล้ว? เปิดข้อมูลชุดนั้นแล้วกด "สร้างไฟล์ Excel สำหรับกรอกงวดถัดไป" เพื่อได้แบบฟอร์มที่มีคอลัมน์เหมือนเดิม</p>
    </div>
</main>
@endsection
