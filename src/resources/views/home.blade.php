@extends('layouts.app')

@section('title', 'รายงานของฉัน')

@use('App\Support\Thai')

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
            <span style="display:flex;gap:.6rem;flex-wrap:wrap">
            <a class="btn btn-outline btn-lg" href="{{ route('excel-templates.index') }}">ไฟล์ Excel แนะนำ</a>
            <a class="btn btn-primary btn-lg" href="{{ route('datasets.create') }}">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M6 10l6-6 6 6M4 20h16"/></svg>
                สร้างรายงานใหม่
            </a>
            </span>
        </div>

        @if (session('status'))
            <div class="alert alert-ok" role="status">{{ session('status') }}</div>
        @endif

        @if ($dashboards->isNotEmpty())
            <div class="card" style="margin-bottom:1.2rem">
                <h2 style="font-size:1.2rem">รายงานของฉัน</h2>
                <div class="table-wrap">
                    <table class="ds-table">
                        <thead><tr><th>รายงาน</th><th>ข้อมูล</th><th>เวอร์ชัน</th><th>แก้ไขล่าสุด</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($dashboards as $db)
                                <tr>
                                    <td><a href="{{ route('dashboards.show', $db) }}"><strong>{{ $db->title }}</strong></a>
                                        @if ($db->currentVersion?->source === 'ai')<span class="badge" style="margin-left:.3rem">✦ AI</span>@endif</td>
                                    <td>{{ $db->dataset?->name ?? '—' }}<br><span class="muted" style="font-size:.88rem">{{ $db->dataset?->period_label }}</span></td>
                                    <td>{{ $db->currentVersion?->version }}</td>
                                    <td style="white-space:nowrap">{{ Thai::date($db->updated_at, true) }}</td>
                                    <td>@if (! empty($db->currentVersion?->spec['infographic']['enabled']))<a href="{{ route('dashboards.infographic', $db) }}">อินโฟกราฟิก</a>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if ($datasets->isEmpty())
            <div class="card empty">
                <div class="icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/></svg></div>
                <h2 style="font-size:1.3rem">ยังไม่มีรายงาน</h2>
                <p class="muted" style="max-width:32rem;margin:0 auto 1rem">เริ่มจากอัปโหลดไฟล์ Excel ที่คุณใช้อยู่ ระบบจะช่วยหาตารางข้อมูลให้</p>
                <a class="btn btn-outline" href="{{ route('datasets.create') }}">อัปโหลดไฟล์แรก</a>
            </div>
        @else
            <div class="card">
                <h2 style="font-size:1.2rem">ข้อมูลที่นำเข้า</h2>
                <div class="table-wrap">
                    <table class="ds-table">
                        <thead><tr><th>ชื่อ</th><th>งวด</th><th>แถว</th><th>สถานะ</th><th>อัปโหลดเมื่อ</th></tr></thead>
                        <tbody>
                            @foreach ($datasets as $d)
                                <tr>
                                    <td><a href="{{ route('datasets.show', $d) }}"><strong>{{ $d->name }}</strong></a><br><span class="muted" style="font-size:.88rem">{{ $d->sheet_name ? 'ชีต '.$d->sheet_name : $d->original_filename }}</span></td>
                                    <td>{{ $d->period_label ?? '—' }}</td>
                                    <td>{{ $d->row_count ? number_format($d->row_count) : '—' }}</td>
                                    <td><span @class(['badge', 'badge-good' => $d->status === 'ready', 'badge-off' => $d->status === 'failed'])>{{ $d->statusLabel() }}</span></td>
                                    <td style="white-space:nowrap">{{ Thai::date($d->created_at, true) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
</main>
@endsection
