@extends('layouts.app')

@section('title', 'อินโฟกราฟิก: '.$spec['title'])

@php
    $g = $spec['infographic'] ?? null;
    $palette = ['green' => ['#2fa84f', '#1f7a37', '#e3f5e7', '#5fd17b'], 'red' => ['#e2393b', '#b3191c', '#fde6e6', '#ff6b6d'],
        'blue' => ['#1f7ae0', '#1659a8', '#e2eefc', '#5aa6ff'], 'orange' => ['#e88a1a', '#a85d00', '#fff0dc', '#ffb34d'],
        'navy' => ['#1f3b6e', '#14284d', '#e8eef8', '#4f7cc4'], 'gray' => ['#6b7280', '#374151', '#eceef2', '#9ca3af']];
@endphp

@push('head')
<script src="https://cdnjs.cloudflare.com/ajax/libs/echarts/5.5.0/echarts.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
@endpush

@section('body')
<main>
    <div class="container" style="max-width:1600px">
        <div class="page-head">
            <div>
                <p style="margin:0"><a href="{{ route('dashboards.show', $dashboard) }}">‹ กลับไปที่รายงาน</a></p>
                <h1 style="font-size:1.6rem;margin:.2rem 0 0">อินโฟกราฟิก 1 หน้า</h1>
            </div>
            @if ($data)
                <div class="toolbar-actions">
                    <button class="btn btn-ghost" type="button" data-export="png">ดาวน์โหลด PNG</button>
                    <button class="btn btn-primary" type="button" data-export="jpeg">ดาวน์โหลด JPEG</button>
                </div>
            @endif
        </div>
        @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
        @if (session('error'))<div class="alert alert-error" role="alert">{{ session('error') }}</div>@endif
        @if ($errors->any())<div class="alert alert-error" role="alert">{{ $errors->first() }}</div>@endif

        @if (! $data)
            <div class="card empty">
                <h2 style="font-size:1.3rem">ยังไม่ได้ตั้งค่าอินโฟกราฟิก</h2>
                <p class="muted">เลือกตัวเลข 2–4 ส่วนที่รวมกันเป็นยอดทั้งหมด (เช่น ใช้จริง + ผูกพัน + คงเหลือ = งบทั้งหมด) ในแบบฟอร์มด้านล่าง</p>
            </div>
        @else
            <div class="ig-fit" id="fit">
                <div class="ig-scaler" id="scaler">
                    <div class="ig-canvas" id="canvas">
                        @if (! empty($g['image']))
                            <div class="ig-hero"><img alt="" src="{{ route('dashboards.image', $dashboard) }}?v={{ md5($g['image']) }}"></div>
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
                </div>
            </div>
        @endif

        @if ($canEdit)
            <details class="box" style="margin-top:1.2rem" @if (! $data) open @endif>
                <summary>ตั้งค่าอินโฟกราฟิก</summary>
                <form method="POST" action="{{ route('dashboards.infographic.update', $dashboard) }}" style="display:grid;gap:.8rem;margin-top:.8rem">
                    @csrf @method('PUT')
                    <div class="grid-2">
                        <label class="muted" style="display:grid;gap:.2rem;font-weight:600">ชื่อบนแถบหัว<input type="text" name="title" value="{{ $g['title'] ?? '' }}" maxlength="200"></label>
                        <label class="muted" style="display:grid;gap:.2rem;font-weight:600">ป้ายช่วงเวลา<input type="text" name="period" value="{{ $g['period'] ?? '' }}" maxlength="80" placeholder="เช่น เดือน กันยายน 2569"></label>
                        <label class="muted" style="display:grid;gap:.2rem;font-weight:600">หน่วยงาน<input type="text" name="unit" value="{{ $g['unit'] ?? '' }}" maxlength="150"></label>
                        <label class="muted" style="display:grid;gap:.2rem;font-weight:600">ชื่อแถวรวม<input type="text" name="total_label" value="{{ $g['total_label'] ?? 'ทั้งหมด' }}" maxlength="60"></label>
                    </div>
                    <p class="muted" style="margin:0">ส่วนประกอบ (2–4 ส่วน รวมกันเป็นยอดทั้งหมด)</p>
                    @for ($i = 0; $i < 4; $i++)
                        @php($part = $g['parts'][$i] ?? null)
                        <div class="form-row">
                            <label>ชื่อส่วนที่ {{ $i + 1 }}<input type="text" name="parts[{{ $i }}][label]" value="{{ $part['label'] ?? '' }}" maxlength="60" @if ($i < 2) required @endif></label>
                            <label>คอลัมน์<select name="parts[{{ $i }}][column]">
                                @if ($i >= 2)<option value="">— ไม่ใช้ —</option>@endif
                                @foreach ($columns as $c)<option value="{{ $c->slug }}" @selected(($part['columns'][0] ?? null) === $c->slug)>{{ $c->display_name }}</option>@endforeach
                            </select></label>
                            <label>สี<select name="parts[{{ $i }}][color]">
                                @foreach (['green' => 'เขียว', 'red' => 'แดง', 'blue' => 'น้ำเงิน', 'orange' => 'ส้ม', 'navy' => 'กรมท่า', 'gray' => 'เทา'] as $k => $l)
                                    <option value="{{ $k }}" @selected(($part['color'] ?? ['green', 'red', 'blue', 'orange'][$i]) === $k)>{{ $l }}</option>
                                @endforeach
                            </select></label>
                        </div>
                    @endfor
                    <div><button class="btn btn-primary" type="submit">บันทึกอินโฟกราฟิก</button></div>
                </form>
                @if ($data)
                    <form method="POST" action="{{ route('dashboards.image.upload', $dashboard) }}" enctype="multipart/form-data" class="form-row" style="margin-top:1rem;border-top:1px solid var(--border);padding-top:1rem">
                        @csrf
                        <label>รูปหัวรายงาน (อาคาร/โลโก้ · jpg/png ≤ 5 MB)<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>
                        <button class="btn btn-outline" type="submit">อัปโหลดรูป</button>
                    </form>
                @endif
            </details>
        @endif
    </div>
</main>
@endsection

@push('scripts')
@if ($data)
<script>
(function () {
    var parts = @json($data['parts']);
    var pal = @json($palette);
    var fit = document.getElementById('fit'), scaler = document.getElementById('scaler');
    function rescale() { scaler.style.transform = 'scale(' + (fit.clientWidth / 1536) + ')'; }
    window.addEventListener('resize', rescale); rescale();

    (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () {
        var chart = echarts.init(document.getElementById('ig-pie'), null, { devicePixelRatio: 2 });
        chart.setOption({ animation: false, series: [
            { type: 'pie', radius: [0, '86%'], center: ['50%', '53%'], silent: true, label: { show: false }, data: [{ value: 1, itemStyle: { color: 'rgba(20,40,70,.18)' } }] },
            { type: 'pie', radius: [0, '84%'], center: ['50%', '50%'], startAngle: 90, clockwise: false, avoidLabelOverlap: false,
              itemStyle: { borderColor: '#fff', borderWidth: 4 },
              label: { show: true, position: 'inside', color: '#fff', fontFamily: 'Sarabun', fontWeight: 800, textShadowColor: 'rgba(0,0,0,.35)', textShadowBlur: 6,
                       formatter: function (p) { var s = p.data.pct < 25 ? 's' : ''; return '{n' + s + '|' + p.name.replace(/\s+/, '\n') + '}\n{p' + s + '|' + p.data.pct + '%}'; },
                       rich: { n: { fontSize: 28, fontWeight: 700, lineHeight: 34, color: '#fff', align: 'center' }, p: { fontSize: 60, fontWeight: 800, lineHeight: 70, color: '#fff', align: 'center' },
                               ns: { fontSize: 22, fontWeight: 700, lineHeight: 28, color: '#fff', align: 'center' }, ps: { fontSize: 42, fontWeight: 800, lineHeight: 50, color: '#fff', align: 'center' } } },
              data: parts.map(function (p) { return { name: p.label, value: Math.max(p.value, 0), pct: p.pct,
                  itemStyle: { color: new echarts.graphic.RadialGradient(0.42, 0.38, 0.9, [{ offset: 0, color: pal[p.color][3] }, { offset: 1, color: pal[p.color][0] }]) } }; }) }
        ] });
        window.__READY__ = true;
    });

    document.querySelectorAll('[data-export]').forEach(function (b) {
        b.addEventListener('click', function () {
            var type = b.dataset.export, label = b.textContent; b.disabled = true; b.textContent = 'กำลังสร้างไฟล์…';
            html2canvas(document.getElementById('canvas'), { scale: 1, backgroundColor: '#ffffff', useCORS: true,
                onclone: function (doc) { doc.getElementById('scaler').style.transform = 'none'; var st = doc.createElement('style'); st.textContent = '.ig-bottom,.ig-row,.ig-ico{box-shadow:none!important}'; doc.head.appendChild(st); }
            }).then(function (cv) {
                var a = document.createElement('a');
                a.download = @json(preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', $spec['title'])) + (type === 'jpeg' ? '.jpg' : '.png');
                a.href = cv.toDataURL(type === 'jpeg' ? 'image/jpeg' : 'image/png', 0.92); a.click();
            }).finally(function () { b.disabled = false; b.textContent = label; });
        });
    });
})();
</script>
@endif
@endpush
