@extends('layouts.render')

@section('title', $spec['title'])

@use('App\Support\Thai')

@php
    $widgets = collect($spec['widgets']);
    $kpis = $widgets->where('type', 'kpi')->values();
    $cards = $widgets->whereNotIn('type', ['kpi'])->values();
    $chartTypes = ['bar', 'line', 'pie', 'gauge'];
    $sourceLabel = $summary['source'] ?? null;
@endphp

@push('head')
<style>
    :root { --sheet-w: {{ $width }}px; }
    @if ($portrait) @page { size: A4 portrait; margin: 10mm 10mm 14mm; } @endif
</style>
@endpush

@section('body')
<div class="sheet {{ $portrait ? 'portrait' : '' }}">
    <header class="r-head">
        <div class="r-brand">
            <div class="r-logo" aria-hidden="true">▮</div>
            <div>
                <h1>{{ $spec['title'] }}</h1>
                <div class="r-sub">{{ $spec['subtitle'] ?? '' }}</div>
            </div>
        </div>
        <div class="r-meta">
            ข้อมูล: <b>{{ $dataset->name }}</b>{{ $dataset->period_label ? ' · งวด '.$dataset->period_label : '' }}<br>
            ผู้จัดทำ: <b>{{ $dashboard->user->name }}</b>{{ $dashboard->user->pos_name ? ' · '.$dashboard->user->pos_name : '' }}<br>
            ออกรายงาน: <b>{{ Thai::date(now()) }}</b>
        </div>
    </header>

    <h2 id="sec-toc">สารบัญ</h2>
    <ol class="toc">
        <li><a href="#sec-summary">บทสรุปสำหรับผู้บริหาร</a></li>
        <li><a href="#sec-kpi">ตัวเลขสำคัญ</a></li>
        <li><a href="#sec-charts">กราฟและตารางสรุป</a>
            <ul class="sub">@foreach ($cards as $w)<li><a href="#w-{{ $w['id'] }}">{{ $w['title'] }}</a></li>@endforeach</ul>
        </li>
        <li><a href="#sec-appendix">ภาคผนวก: ตารางข้อมูลประกอบกราฟ</a></li>
    </ol>

    <h2 id="sec-summary">บทสรุปสำหรับผู้บริหาร</h2>
    <div class="sum-box">
        @if (! empty($summary['bullets']))
            <ol>@foreach ($summary['bullets'] as $b)<li>{{ $b['text'] }}</li>@endforeach</ol>
            <div class="src">{{ $sourceLabel === 'ai' ? 'เขียนโดย AI จากตัวเลขที่ระบบคำนวณ และตรวจตัวเลขกับข้อมูลแล้ว' : 'บทสรุปแบบมาตรฐาน (ไม่ใช้ AI)' }}</div>
        @else
            <span class="muted">ไม่มีบทสรุป</span>
        @endif
    </div>

    @if ($kpis->isNotEmpty())
        <h2 id="sec-kpi">ตัวเลขสำคัญ</h2>
        <div class="kpi-row">
            @foreach ($kpis as $w)
                @php($d = $computed[$w['id']] ?? null)
                @php($k = Thai::kpi($d['value'] ?? null, $d['unit'] ?? null))
                <a class="kpi-card" id="w-{{ $w['id'] }}" href="#app-kpis" title="ดูตารางตัวเลขสำคัญในภาคผนวก">
                    <div class="l">{{ $w['title'] }}</div>
                    <div class="v">{{ $k['value'] }}</div>
                    <div class="u">{{ $k['unit'] }}{{ $k['sub'] ? ' · '.$k['sub'] : '' }}</div>
                </a>
            @endforeach
        </div>
    @endif

    @if ($cards->isNotEmpty())
        <h2 id="sec-charts" class="sec-break">กราฟและตารางสรุป</h2>
        <div class="cards">
            @foreach ($cards as $w)
                @php($d = $computed[$w['id']] ?? null)
                <section class="rcard {{ $w['type'] === 'table' ? 'full' : '' }}" id="w-{{ $w['id'] }}">
                    <h3>{{ $w['title'] }}</h3>
                    @if (! $d)
                        <p class="r-note">ไม่มีข้อมูล</p>
                    @elseif ($w['type'] === 'table')
                        @include('render._table', ['d' => $d])
                    @else
                        <div class="r-chart {{ $w['type'] }}" data-widget="{{ $w['id'] }}"></div>
                        <a class="inner more" href="#app-{{ $w['id'] }}">ดูตารางข้อมูล ›</a>
                    @endif
                </section>
            @endforeach
        </div>
    @endif

    <h2 id="sec-appendix" class="sec-break">ภาคผนวก: ตารางข้อมูลประกอบกราฟ</h2>
    @if ($kpis->isNotEmpty())
        <div class="app-table" id="app-kpis">
            <h3>ตัวเลขสำคัญ</h3>
            <table class="num-table">
                <thead><tr><th>รายการ</th><th>ค่า</th><th>หน่วย</th></tr></thead>
                <tbody>
                    @foreach ($kpis as $w)
                        @php($d = $computed[$w['id']] ?? null)
                        <tr><td>{{ $w['title'] }}</td><td class="n">{{ $d && $d['value'] !== null ? number_format($d['value'], 2) : '—' }}</td><td>{{ $d['unit'] ?? '' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    @foreach ($cards->filter(fn ($w) => in_array($w['type'], ['bar', 'line', 'pie'], true)) as $w)
        @php($d = $computed[$w['id']] ?? null)
        @if ($d)
            <div class="app-table" id="app-{{ $w['id'] }}">
                <h3>{{ $w['title'] }}</h3>
                <table class="num-table">
                    <thead><tr><th>{{ $d['dimension_label'] ?? '' }}</th>@foreach ($d['series'] as $s)<th>{{ $s['name'] }}{{ $s['unit'] ? ' ('.$s['unit'].')' : '' }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($d['categories'] as $i => $cat)
                            <tr><td>{{ $cat }}</td>@foreach ($d['series'] as $s)<td class="n">{{ $s['data'][$i] !== null ? number_format($s['data'][$i], 2) : '—' }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endforeach
    <p class="r-note">ตัวเลขทุกตัวคำนวณจากข้อมูลที่นำเข้า ({{ $dataset->name }}, {{ number_format($dataset->row_count) }} รายการ) · สร้างด้วย MSC Report</p>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var spec = @json($spec), data = @json($computed);
    var byId = {}; spec.widgets.forEach(function (w) { byId[w.id] = w; });
    document.querySelectorAll('.r-chart').forEach(function (box) {
        var id = box.dataset.widget;
        MscDashboard.drawChart(box, byId[id], data[id], spec.theme, { renderer: 'svg' });
    });
    MscDashboard.markReady();
})();
</script>
@endpush
