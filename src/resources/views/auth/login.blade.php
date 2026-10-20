@extends('layouts.app')

@section('title', 'เข้าสู่ระบบ')

@section('body')
<div class="auth-wrap">
    <div class="card auth-card">
        <h1>เข้าสู่ระบบ</h1>
        <p class="muted">ใช้บัญชี UP Account ของมหาวิทยาลัย<br>สำหรับบุคลากรคณะวิทยาศาสตร์การแพทย์</p>

        @if (session('error'))
            <div class="alert alert-error" role="alert">{{ session('error') }}</div>
        @endif

        @if ($ssoConfigured)
            <a class="btn btn-primary btn-lg btn-block" href="{{ route('sso.redirect') }}">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
                เข้าสู่ระบบด้วย UP Account
            </a>
        @else
            <button class="btn btn-primary btn-lg btn-block" type="button" disabled>เข้าสู่ระบบด้วย UP Account</button>
            <p class="muted" style="font-size:.9rem;margin-top:.6rem">ยังไม่ได้ตั้งค่า MEDSCI_SSO_CLIENT_ID / CLIENT_SECRET ในไฟล์ .env</p>
        @endif

        @if ($devLogin)
            <div class="divider">สำหรับเครื่องพัฒนาเท่านั้น</div>
            <div class="dev-box">
                <p style="margin:0 0 .6rem">เข้าสู่ระบบเป็น <strong>{{ $devUsername }}</strong> โดยไม่ผ่าน SSO<br><span class="muted" style="font-size:.9rem">ปุ่มนี้แสดงเฉพาะ APP_ENV=local และปิดได้ด้วย DEV_LOGIN_ENABLED=false</span></p>
                <form method="POST" action="{{ route('dev.login') }}">
                    @csrf
                    <button class="btn btn-outline btn-block" type="submit">เข้าสู่ระบบ (โหมดพัฒนา)</button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
