{{-- ผืนผ้าใบอินโฟกราฟิกขนาดคงที่ 1536×1024 — ใช้ร่วมกันทั้งหน้าดูและหน้า render สำหรับส่งออก (spec FR-6.5) --}}
@php
    $palette = ['green' => ['#2fa84f', '#1f7a37', '#e3f5e7', '#5fd17b'], 'red' => ['#e2393b', '#b3191c', '#fde6e6', '#ff6b6d'],
        'blue' => ['#1f7ae0', '#1659a8', '#e2eefc', '#5aa6ff'], 'orange' => ['#e88a1a', '#a85d00', '#fff0dc', '#ffb34d'],
        'navy' => ['#1f3b6e', '#14284d', '#e8eef8', '#4f7cc4'], 'gray' => ['#6b7280', '#374151', '#eceef2', '#9ca3af']];
    $imageUrl = ! empty($g['image']) ? (isset($imageRoute) ? $imageRoute : route('dashboards.image', $dashboard)).'?v='.md5($g['image']) : null;
@endphp
<div class="ig-canvas" id="canvas">
    @if ($imageUrl)
        <div class="ig-hero"><img alt="" src="{{ $imageUrl }}"></div>
    @endif
    <div class="ig-title">{{ $g['title'] ?: $spec['title'] }}</div>
    @if ($g['period'])<div class="ig-month">{{ $g['period'] }}</div>@endif
    @if ($g['unit'])<div class="ig-unit">{{ $g['unit'] }}</div>@endif
    <div id="ig-pie" class="ig-pie"></div>
    <div class="ig-tbl">
        <div class="ig-tbl-head"><div>รายการ</div><div>ร้อยละ</div><div>จำนวนเงิน (บาท)</div></div>
        @foreach ($data['parts'] as $i => $p)
            @php($c = $palette[$p['color']])
            <div class="ig-row" style="background:{{ $c[2] }}">
                <div class="ig-name"><span class="ig-ico" style="background:radial-gradient(circle at 35% 30%, {{ $c[3] }}, {{ $c[1] }})">{{ $i + 1 }}</span><span><b>{{ $p['label'] }}</b><small>คิดเป็นร้อยละ</small></span></div>
                <div class="ig-pct" style="color:{{ $c[1] }}">{{ $p['pct'] }}%</div>
                <div class="ig-amt">{{ number_format($p['value'], 2) }}</div>
            </div>
        @endforeach
        <div class="ig-row" style="background:#eceef2">
            <div class="ig-name"><span class="ig-ico" style="background:radial-gradient(circle at 35% 30%, #7a8296, #2c3240)">Σ</span><span><b>{{ $g['total_label'] }}</b><small>คิดเป็นร้อยละ</small></span></div>
            <div class="ig-pct" style="font-size:34px">100%</div>
            <div class="ig-amt">{{ number_format($data['total'], 2) }}</div>
        </div>
    </div>
    <div class="ig-bottom" style="grid-template-columns:repeat({{ count($data['parts']) }}, 1fr)">
        @foreach ($data['parts'] as $i => $p)
            @php($c = $palette[$p['color']])
            <div class="ig-card" style="color:{{ $c[1] }}">
                <span class="ig-ico big" style="background:radial-gradient(circle at 35% 30%, {{ $c[3] }}, {{ $c[1] }})">{{ $i + 1 }}</span>
                <div><div class="ig-lbl">{{ $p['label'] }}</div><div class="ig-val" @if ($i === 0) style="color:#1b2330" @endif>{{ number_format($p['value'], 2) }}<small>บาท</small></div><div class="ig-p">คิดเป็นร้อยละ<b>{{ $p['pct'] }}</b></div></div>
            </div>
        @endforeach
    </div>
    <div class="ig-src">ข้อมูล: {{ $dashboard->dataset->name }}{{ $dashboard->dataset->period_label ? ' · งวด '.$dashboard->dataset->period_label : '' }} · สร้างด้วย MSC Report</div>
</div>
<script>window.MSC_IG = { parts: @json($data['parts']), palette: @json($palette) };</script>
