@extends('layouts.app')

@section('title', 'ส่งออกไฟล์')

@use('App\Support\Thai')

@if (in_array($export->status, ['queued', 'processing'], true))
    @push('head')<meta http-equiv="refresh" content="2">@endpush
@endif

@section('body')
<main>
    <div class="container" style="max-width:44rem">
        <p style="margin:0 0 .4rem"><a href="{{ route('dashboards.show', $export->dashboard_id) }}">‹ กลับไปที่รายงาน</a></p>
        <h1 style="font-size:1.7rem">ส่งออก: {{ $export->label() }}</h1>
        <p class="muted">{{ $export->dashboard->title }}</p>

        @if (in_array($export->status, ['queued', 'processing'], true))
            <div class="card empty">
                <div class="spinner" aria-hidden="true"></div>
                <h2 style="font-size:1.3rem">{{ $export->status === 'queued' ? 'อยู่ในคิว…' : 'กำลังสร้างไฟล์…' }}</h2>
                <p class="muted">ใช้เวลาประมาณ 5–20 วินาที หน้านี้จะแสดงปุ่มดาวน์โหลดเมื่อเสร็จ</p>
            </div>
        @elseif ($export->status === 'failed')
            <div class="alert alert-error" role="alert">{{ $export->error_message }}</div>
            @if ($export->user_id === auth()->id())
                <form method="POST" action="{{ route('exports.retry', $export) }}">@csrf<button class="btn btn-primary" type="submit">ลองสร้างใหม่</button></form>
            @endif
        @elseif ($export->isReady())
            <div class="card" style="text-align:center">
                <div style="font-size:3rem" aria-hidden="true">{{ $export->format === 'pdf' ? '📄' : '🖼️' }}</div>
                <h2 style="font-size:1.3rem;margin:.2rem 0">{{ $export->filename }}</h2>
                <p class="muted" style="margin:0 0 1rem">{{ Thai::fileSize((int) $export->file_size) }} · สร้างใน {{ number_format(($export->duration_ms ?? 0) / 1000, 1) }} วินาที · เก็บไว้ถึง {{ Thai::date($export->expires_at) }}</p>
                <a class="btn btn-primary btn-lg" href="{{ route('exports.download', $export) }}">ดาวน์โหลดไฟล์</a>
            </div>
        @else
            <div class="alert alert-error" role="alert">ไฟล์นี้หมดอายุแล้ว กรุณาสั่งส่งออกใหม่จากหน้ารายงาน</div>
        @endif
    </div>
</main>
@endsection
