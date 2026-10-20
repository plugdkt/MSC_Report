<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'MSC Report') — MSC Report</title>
    <link rel="stylesheet" href="{{ asset('css/fonts.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    @stack('head')
</head>
<body>
    <header class="site-header">
        <div class="container nav">
            <a class="brand" href="{{ auth()->check() ? route('home') : route('landing') }}">
                <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></span>
                MSC Report
            </a>

            @auth
                <nav class="nav-links" aria-label="เมนูหลัก">
                    <a href="{{ route('home') }}" @class(['active' => request()->routeIs('home')])>รายงานของฉัน</a>
                    <a href="{{ route('me.ai') }}" @class(['active' => request()->routeIs('me.ai')])>AI ของฉัน</a>
                    @if (auth()->user()->isAdmin())
                        <details class="nav-menu">
                            <summary @class(['active' => request()->routeIs('admin.*')])>ผู้ดูแลระบบ ▾</summary>
                            <div class="nav-menu-list">
                                <a href="{{ route('admin.overview') }}">ภาพรวมระบบ</a>
                                <a href="{{ route('admin.users.index') }}">จัดการผู้ใช้</a>
                                <a href="{{ route('admin.codes.index') }}">ตารางรหัส</a>
                                <a href="{{ route('admin.ai.edit') }}">ตั้งค่า AI</a>
                                <a href="{{ route('admin.settings.edit') }}">ตั้งค่าระบบ</a>
                                <a href="{{ route('admin.audit.index') }}">บันทึกกิจกรรม</a>
                            </div>
                        </details>
                    @endif
                </nav>
            @endauth

            <span class="nav-spacer"></span>

            <div class="font-size" role="group" aria-label="ขนาดตัวอักษร">
                <button type="button" data-size="md" aria-pressed="true" aria-label="ขนาดปกติ">ก</button>
                <button type="button" data-size="lg" aria-pressed="false" aria-label="ขนาดใหญ่">ก</button>
                <button type="button" data-size="xl" aria-pressed="false" aria-label="ขนาดใหญ่มาก">ก</button>
            </div>

            @auth
                <div class="user-chip">
                    <span class="avatar" aria-hidden="true">{{ mb_substr(auth()->user()->name, 0, 1) }}</span>
                    <span class="who">{{ auth()->user()->name }}<small>{{ auth()->user()->roleLabel() }}</small></span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="btn btn-ghost" type="submit">ออกจากระบบ</button>
                    </form>
                </div>
            @else
                @unless (request()->routeIs('login'))
                    <a class="btn btn-primary" href="{{ route('login') }}">เข้าสู่ระบบ</a>
                @endunless
            @endauth
        </div>
    </header>

    @yield('body')

    <footer class="site-footer">
        <div class="container">
            <span>© {{ now()->year + 543 }} MSC Report · {{ \App\Support\Org::name() }}</span>
            <span>เข้าสู่ระบบด้วย UP Account ผ่าน MEDSCI ACC</span>
        </div>
    </footer>

    <script src="{{ asset('js/font-size.js') }}"></script>
    @stack('scripts')
</body>
</html>
