@extends('layouts.app')

@section('title', $spec['title'])

@push('head')
<script src="https://cdnjs.cloudflare.com/ajax/libs/echarts/5.5.0/echarts.min.js"></script>
@endpush

@section('body')
<main>
    <div class="container">
        @include('datasets._steps', ['current' => 5])

        <div class="page-head">
            <div>
                <h1>{{ $spec['title'] }}</h1>
                <p class="muted" style="margin:.2rem 0 0">
                    {{ $spec['subtitle'] ?? '' }}
                    <span class="badge" style="margin-left:.3rem">{{ $version->source === 'ai' ? '✦ ออกแบบโดย AI' : 'กฎมาตรฐาน (ไม่ใช้ AI)' }}</span>
                </p>
            </div>
            <a class="btn btn-outline" href="{{ route('datasets.show', $dataset) }}">ข้อมูลต้นทาง</a>
        </div>

        <div class="alert alert-ok" role="status" style="background:var(--primary-soft);color:var(--primary);border-color:#c9d6ec">
            ต้นแบบ M2: แสดงผลจากแผนที่ได้ — การจัดวาง/แก้ไข บทสรุปผู้บริหาร และส่งออก PDF/JPEG จะเพิ่มใน M3–M4
        </div>

        <div class="dash-grid" id="dash"></div>
    </div>
</main>
@endsection

@push('scripts')
<script>
(function () {
    var spec = @json($spec);
    var data = @json($computed);
    var root = document.getElementById('dash');
    var fmt = function (v, unit) {
        if (v === null || v === undefined) { return '—'; }
        var d = unit === '%' ? 1 : (Math.abs(v) >= 1000 || unit === 'บาท' ? 2 : 2);
        var s = Number(v).toLocaleString('th-TH', { minimumFractionDigits: unit === 'บาท' ? 2 : 0, maximumFractionDigits: d });
        return unit === '%' ? s + '%' : s;
    };
    var palette = ['#1f3b6e', '#b7822b', '#4f7cc4', '#1e7a4c', '#8fa7d1', '#b42318', '#6b5b95', '#5f9ea0'];
    var charts = [];

    spec.widgets.forEach(function (w) {
        var d = data[w.id]; if (!d) { return; }
        var el = document.createElement('section');
        el.className = 'card dash-' + w.type;
        el.innerHTML = '<h2 class="w-title"></h2>';
        el.querySelector('.w-title').textContent = w.title;
        root.appendChild(el);

        if (w.type === 'kpi') {
            var big = d.unit === 'บาท' && Math.abs(d.value || 0) >= 1e6;
            var v = document.createElement('div'); v.className = 'kpi-value';
            v.textContent = big ? (d.value / 1e6).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : fmt(d.value, d.unit);
            var u = document.createElement('div'); u.className = 'muted'; u.textContent = big ? 'ล้านบาท' : (d.unit && d.unit !== '%' ? d.unit : (d.label || ''));
            el.appendChild(v); el.appendChild(u);
            if (big) { var s = document.createElement('div'); s.className = 'kpi-sub'; s.textContent = fmt(d.value, 'บาท') + ' บาท'; el.appendChild(s); }
            return;
        }
        if (w.type === 'table') {
            var wrap = document.createElement('div'); wrap.className = 'table-wrap';
            var t = document.createElement('table'); t.className = 'num-table';
            var thead = '<thead><tr>' + d.columns.map(function (c) { return '<th>' + c.label.replace(/</g, '&lt;') + (c.unit ? ' (' + c.unit + ')' : '') + '</th>'; }).join('') + '</tr></thead>';
            var body = d.rows.map(function (r, i) {
                var total = d.has_total && i === d.rows.length - 1;
                return '<tr' + (total ? ' class="total"' : '') + '>' + r.map(function (c, j) {
                    return j === 0 && d.columns[0].unit === null ? '<td>' + String(c).replace(/</g, '&lt;') + '</td>' : '<td class="n">' + fmt(c, d.columns[j].unit) + '</td>';
                }).join('') + '</tr>';
            }).join('');
            t.innerHTML = thead + '<tbody>' + body + '</tbody>';
            wrap.appendChild(t); el.appendChild(wrap);
            return;
        }

        var box = document.createElement('div'); box.className = 'chart-box'; el.appendChild(box);
        charts.push({ el: box, w: w, d: d });
    });

    function option(w, d) {
        var fs = Math.round(parseFloat(getComputedStyle(document.documentElement).fontSize) * 0.85);
        var base = { textStyle: { fontFamily: 'Sarabun, Tahoma, sans-serif', fontSize: fs, color: '#445064' }, color: palette, animationDuration: 500,
                     tooltip: { trigger: w.type === 'pie' || w.type === 'gauge' ? 'item' : 'axis', textStyle: { fontFamily: 'Sarabun', fontSize: fs } } };
        if (w.type === 'gauge') {
            return Object.assign(base, { series: [{ type: 'gauge', min: 0, max: 100, progress: { show: true, width: 18 }, axisLine: { lineStyle: { width: 18 } },
                detail: { formatter: '{value}%', fontSize: fs * 2, fontWeight: 700, color: '#1b2330' }, data: [{ value: d.value }],
                axisLabel: { fontSize: fs * 0.8 }, pointer: { show: false }, title: { show: false },
                markLine: d.target ? {} : undefined }] });
        }
        if (w.type === 'pie') {
            return Object.assign(base, { legend: { bottom: 0, type: 'scroll', textStyle: { fontSize: fs } },
                series: [{ type: 'pie', radius: ['38%', '68%'], center: ['50%', '45%'], label: { formatter: '{d}%', fontSize: fs, fontWeight: 700 },
                    data: d.categories.map(function (c, i) { return { name: c, value: d.series[0].data[i] }; }) }] });
        }
        var horizontal = w.type === 'bar' && d.categories.length > 6;
        var cat = { type: 'category', data: d.categories, axisLabel: { fontSize: fs, color: '#1b2330', width: horizontal ? 180 : 90, overflow: 'break', interval: 0 }, axisTick: { show: false } };
        var val = { type: 'value', axisLabel: { fontSize: fs, formatter: function (v) { return Math.abs(v) >= 1e6 ? (v / 1e6).toFixed(1) + ' ลบ.' : Number(v).toLocaleString('th-TH'); } }, splitLine: { lineStyle: { color: '#e6eaf0' } } };
        return Object.assign(base, {
            legend: d.series.length > 1 ? { top: 0, textStyle: { fontSize: fs } } : undefined,
            grid: { left: 8, right: 24, top: d.series.length > 1 ? 36 : 12, bottom: 8, containLabel: true },
            xAxis: horizontal ? val : cat, yAxis: horizontal ? Object.assign(cat, { inverse: true }) : val,
            series: d.series.map(function (s) {
                return { name: s.name, type: w.type, data: s.data, smooth: false, symbolSize: 8, barMaxWidth: 36,
                         itemStyle: w.type === 'bar' ? { borderRadius: horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0] } : undefined,
                         tooltip: { valueFormatter: function (v) { return fmt(v, s.unit) + (s.unit && s.unit !== '%' ? ' ' + s.unit : ''); } } };
            })
        });
    }

    function render() {
        charts.forEach(function (c) {
            if (!c.chart) { c.chart = echarts.init(c.el); }
            c.chart.setOption(option(c.w, c.d), true);
        });
    }
    (document.fonts ? document.fonts.ready : Promise.resolve()).then(render);
    window.addEventListener('resize', function () { charts.forEach(function (c) { c.chart && c.chart.resize(); }); });
    document.addEventListener('click', function (e) { if (e.target.closest('.font-size button')) { setTimeout(function () { render(); charts.forEach(function (c) { c.chart.resize(); }); }, 50); } });
})();
</script>
@endpush
