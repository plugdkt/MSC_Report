@extends('layouts.app')

@section('title', $spec['title'])

@push('head')
<script src="{{ asset('vendor/echarts.min.js') }}"></script>
@endpush

@php($summary = $version->executive_summary)

@section('body')
<main>
    <div class="container">
        @include('datasets._steps', ['current' => 5])

        <div class="page-head">
            <div>
                <h1>{{ $spec['title'] }}</h1>
                <p class="muted" style="margin:.2rem 0 0">
                    {{ $spec['subtitle'] ?? '' }}
                    <span class="badge" style="margin-left:.3rem">{{ $version->source === 'ai' ? '✦ ออกแบบโดย AI' : ($version->source === 'rule' ? 'กฎมาตรฐาน' : 'แก้ไขโดยผู้ใช้') }}</span>
                    <span class="muted" style="font-size:.88rem">· เวอร์ชัน {{ $version->version }}</span>
                </p>
            </div>
            <div class="toolbar-actions">
                @if ($canEdit)
                    <form method="POST" action="{{ route('dashboards.theme', $dashboard) }}" class="inline-form">
                        @csrf @method('PUT')
                        <label class="sr-only" for="theme">ธีม</label>
                        <select id="theme" name="theme" onchange="this.form.submit()">
                            @foreach ($themes as $key => $label)<option value="{{ $key }}" @selected(($spec['theme'] ?? 'org') === $key)>ธีม: {{ $label }}</option>@endforeach
                        </select>
                    </form>
                    <a class="btn btn-ghost" href="{{ route('dashboards.versions', $dashboard) }}">ประวัติเวอร์ชัน</a>
                    <a class="btn btn-outline" href="{{ route('dashboards.edit', $dashboard) }}">✎ แก้ไข/จัดหน้า</a>
                @endif
                @if (! empty($spec['infographic']['enabled']))
                    <a class="btn btn-outline" href="{{ route('dashboards.infographic', $dashboard) }}">อินโฟกราฟิก 1 หน้า</a>
                @endif
                @if ($canEdit)
                    <details class="export-menu">
                        <summary class="btn btn-primary">⭳ ส่งออก ▾</summary>
                        <form class="export-list" method="POST" action="{{ route('dashboards.exports.store', $dashboard) }}">
                            @csrf
                            <b>รายงานฉบับเต็ม</b>
                            <div class="row">
                                <select name="orientation" aria-label="ทิศทางกระดาษ"><option value="landscape">A4 แนวนอน</option><option value="portrait">A4 แนวตั้ง</option></select>
                                <button class="btn btn-outline" type="submit" name="target" value="report" formnovalidate onclick="this.form.format.value='pdf'">PDF</button>
                            </div>
                            <p class="muted hint">มีสารบัญคลิกได้ บุ๊กมาร์ก ตารางข้อมูลท้ายเล่ม เลขหน้า</p>
                            <b>ภาพ (JPEG / PNG)</b>
                            <div class="row">
                                <select name="scale" aria-label="ความละเอียด"><option value="1">ปกติ</option><option value="2" selected>ชัดมาก 2×</option><option value="3">สูงสุด 3×</option></select>
                            </div>
                            @if (! empty($spec['infographic']['enabled']))
                                <div class="row"><span>อินโฟกราฟิก 1 หน้า</span>
                                    <button class="btn btn-ghost" type="submit" name="target" value="infographic" onclick="this.form.format.value='jpeg'">JPEG</button>
                                    <button class="btn btn-ghost" type="submit" name="target" value="infographic" onclick="this.form.format.value='png'">PNG</button></div>
                            @endif
                            <div class="row"><span>Dashboard ทั้งหน้า</span>
                                <button class="btn btn-ghost" type="submit" name="target" value="dashboard" onclick="this.form.format.value='jpeg'">JPEG</button>
                                <button class="btn btn-ghost" type="submit" name="target" value="dashboard" onclick="this.form.format.value='png'">PNG</button></div>
                            <input type="hidden" name="format" value="pdf">
                        </form>
                    </details>
                @endif
            </div>
        </div>

        @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
        @if (session('error'))<div class="alert alert-error" role="alert">{{ session('error') }}</div>@endif

        <section class="card summary-card" aria-label="บทสรุปสำหรับผู้บริหาร">
            <div class="summary-head">
                <h2>✦ บทสรุปสำหรับผู้บริหาร</h2>
                @if ($summary)
                    <span @class(['badge', 'badge-good' => $summary['source'] === 'ai'])>{{ $summary['source'] === 'ai' ? 'เขียนโดย AI · ตรวจตัวเลขกับข้อมูลแล้ว' : 'แบบมาตรฐาน (ไม่ใช้ AI)' }}</span>
                @endif
            </div>
            @if ($summary && $summary['bullets'])
                <ol>@foreach ($summary['bullets'] as $b)<li>{{ $b['text'] }}</li>@endforeach</ol>
            @else
                <p class="muted" style="margin:0">ยังไม่มีบทสรุป</p>
            @endif
            @if ($canEdit)
                <div class="summary-actions">
                    @if ($aiAvailable)
                        <form method="POST" action="{{ route('dashboards.summary', $dashboard) }}" data-busy="AI กำลังเขียนบทสรุป…">@csrf<input type="hidden" name="ai" value="1"><button class="btn btn-ghost" type="submit">ให้ AI เขียนบทสรุปใหม่</button></form>
                    @endif
                    <form method="POST" action="{{ route('dashboards.summary', $dashboard) }}">@csrf<input type="hidden" name="ai" value="0"><button class="btn btn-ghost" type="submit">ใช้บทสรุปแบบมาตรฐาน</button></form>
                </div>
            @endif
        </section>

        @if ($exports->isNotEmpty())
            <section class="card" style="margin-bottom:1.2rem;padding:.8rem 1.2rem">
                <h2 style="font-size:1.05rem;margin:0 0 .4rem">ไฟล์ที่ส่งออกล่าสุด</h2>
                <ul class="quality">
                    @foreach ($exports as $ex)
                        <li class="q-info"><span class="ic">{{ $ex->format === 'pdf' ? 'P' : 'I' }}</span>
                            <span style="flex:1"><a href="{{ route('exports.show', $ex) }}">{{ $ex->label() }}</a> <span class="muted">· {{ \App\Support\Thai::date($ex->created_at, true) }} · {{ $ex->statusLabel() }}</span></span>
                            @if ($ex->isReady())<a class="btn btn-ghost" style="min-height:2rem;padding:.1rem .8rem" href="{{ route('exports.download', $ex) }}">ดาวน์โหลด</a>@endif</li>
                    @endforeach
                </ul>
            </section>
        @endif

        <div class="dash-board theme-{{ $spec['theme'] ?? 'org' }}" id="dash"></div>
    </div>
</main>
@endsection

@push('scripts')
<script src="{{ asset('js/dashboard-render.js') }}"></script>
<script>
(function () {
    var spec = @json($spec), data = @json($computed), root = document.getElementById('dash'), items = [];
    var rows = spec.widgets.reduce(function (m, w) { return Math.max(m, w.layout.y + w.layout.h); }, 0);
    root.style.setProperty('--rows', rows);
    spec.widgets.forEach(function (w) {
        var el = document.createElement('section');
        el.className = 'card dash-item dash-' + w.type;
        el.style.gridColumn = (w.layout.x + 1) + ' / span ' + w.layout.w;
        el.style.gridRow = (w.layout.y + 1) + ' / span ' + w.layout.h;
        root.appendChild(el);
        items.push({ el: el, w: w });
    });
    function draw() { items.forEach(function (it) { it.r && it.r.dispose && it.r.dispose(); it.r = MscDashboard.renderWidget(it.el, it.w, data[it.w.id], spec.theme); }); }
    (document.fonts ? document.fonts.ready : Promise.resolve()).then(draw);
    window.addEventListener('resize', function () { items.forEach(function (it) { it.r && it.r.resize(); }); });
    document.addEventListener('click', function (e) { if (e.target.closest('.font-size button')) { setTimeout(draw, 30); } });
    document.querySelectorAll('form[data-busy]').forEach(function (f) { f.addEventListener('submit', function () { var b = f.querySelector('button'); b.disabled = true; b.textContent = f.dataset.busy; }); });
})();
</script>
@endpush
