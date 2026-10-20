@extends('layouts.app')

@section('title', 'รายงานของฉัน')

@section('body')
<main>
    <div class="container">
        <div class="page-head">
            <div>
                <p class="muted" style="margin:0">สวัสดี</p>
                <h1>{{ $user->name }}</h1>
                <p class="muted" style="margin:.2rem 0 0">
                    {{ $user->pos_name ?? 'ยังไม่มีข้อมูลตำแหน่ง' }}{{ $user->div_name ? ' · '.$user->div_name : '' }}
                    · <span @class(['badge', 'badge-admin' => $user->isAdmin()])>{{ $user->roleLabel() }}</span>
                </p>
            </div>
            <button class="btn btn-primary btn-lg" type="button" disabled title="เปิดใช้ใน M1">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M6 10l6-6 6 6M4 20h16"/></svg>
                สร้างรายงานใหม่
            </button>
        </div>

        <div class="card empty">
            <div class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg></div>
            <h2 style="font-size:1.3rem">ยังไม่มีรายงาน</h2>
            <p class="muted" style="max-width:32rem;margin:0 auto">ขั้นถัดไป (M1) จะเปิดให้อัปโหลดไฟล์ Excel เพื่อสร้างรายงานฉบับแรก</p>
        </div>
    </div>
</main>
@endsection
