{{-- อินโฟกราฟิกแบบเล่าเรื่อง: ขนาดตามที่เลือก ความสูงตามเนื้อหา — ใช้ร่วมกันทั้งหน้าดูและหน้า render สำหรับส่งออก --}}
@php
    $palettes = [
        'org' => ['#1f3b6e', '#b7822b', '#4f7cc4', '#1e7a4c', '#b42318'],
        'formal' => ['#2f4858', '#33658a', '#758e4f', '#f6ae2d', '#999999'],
        'bright' => ['#2f80ed', '#f2994a', '#27ae60', '#eb5757', '#9b51e0'],
        'green' => ['#1b5e20', '#43a047', '#00897b', '#c0ca33', '#6d4c41'],
    ];
    $pal = $palettes[$spec['theme'] ?? 'org'] ?? $palettes['org'];
    [$igW, $igMinH] = config('export.infographic_sizes')[$g['size'] ?? 'a4p'] ?? [1240, 1754];
    $imageUrl = ! empty($g['image']) ? (isset($imageRoute) ? $imageRoute : route('dashboards.image', $dashboard)).'?v='.md5($g['image']) : null;
    $bullets = $summary['bullets'] ?? [];
    $charts = [];
    $fmt = fn ($v, $unit) => \App\Support\Thai::kpi($v === null ? null : (float) $v, $unit);
@endphp
<div class="ig2" id="canvas" style="width:{{ $igW }}px;min-height:{{ $igMinH }}px;--c0:{{ $pal[0] }};--c1:{{ $pal[1] }};--c2:{{ $pal[2] }};--c3:{{ $pal[3] }}">
    <div class="ig2-head">
        @if ($imageUrl)<div class="ig2-hero"><img alt="" src="{{ $imageUrl }}"></div>@endif
        <div class="ig2-title">{{ $g['title'] ?: $spec['title'] }}</div>
        @if (! empty($g['subtitle']))<p class="ig2-sub">{{ $g['subtitle'] }}</p>@endif
    </div>
    <div class="ig2-grid">
        @foreach ($data['blocks'] as $b)
            @php $wide = in_array($b['type'], ['kpis', 'trend', 'list', 'highlights', 'text'], true) @endphp
            @if ($b['type'] === 'highlights' && ! $bullets)
                @continue
            @endif
            <section class="ig2-block {{ $wide ? 'span2' : '' }}">
                @php $blockTitle = $b['title'] ?? ($b['data']['title'] ?? null) @endphp
                @if ($blockTitle && $b['type'] !== 'kpis')<h3>{{ $blockTitle }}</h3>@endif

                @if ($b['type'] === 'kpis')
                    <div class="ig2-kpis" style="--n:{{ count($b['items']) }}">
                        @foreach ($b['items'] as $i => $it)
                            @php $k = $fmt($it['value'] ?? null, $it['unit'] ?? null) @endphp
                            <div class="ig2-kpi" style="--ci:{{ $pal[$i % 4] }}">
                                <div class="l">{{ $it['title'] }}</div>
                                <div class="v">{{ $k['value'] }}</div>
                                <div class="u">{{ $k['unit'] }}</div>
                                @if ($k['sub'])<div class="s">{{ $k['sub'] }}</div>@endif
                            </div>
                        @endforeach
                    </div>
                @elseif (in_array($b['type'], ['ring', 'trend'], true))
                    @php $charts[] = ['id' => 'ig-'.$b['key'], 'type' => $b['type'] === 'ring' ? $b['data']['type'] : $b['data']['type'], 'title' => $b['data']['title'], 'data' => $b['data']] @endphp
                    <div class="ig2-chart" id="ig-{{ $b['key'] }}"></div>
                @elseif ($b['type'] === 'bars' || $b['type'] === 'list')
                    @php
                        $d = $b['data'];
                        if (($d['type'] ?? null) === 'table') {
                            $names = array_map(fn ($r) => $r[0], $d['rows']);
                            $vals = array_map(fn ($r) => $r[1] ?? null, $d['rows']);
                            $unit = $d['columns'][1]['unit'] ?? null;
                            if (! empty($d['has_total'])) { array_pop($names); array_pop($vals); }
                        } else {
                            $names = $d['categories'];
                            $vals = $d['series'][0]['data'] ?? [];
                            $unit = $d['series'][0]['unit'] ?? null;
                        }
                        $pairs = collect($names)->zip($vals)->filter(fn ($p) => $p[1] !== null)->take($b['limit'] ?? 8)->values();
                        $max = max(1, (float) $pairs->max(fn ($p) => abs($p[1])));
                        $inMillions = $unit === 'บาท' && $max >= 1e6;   // แสดงหน่วยเดียวกันทั้งรายการ
                        $show = fn ($v) => $inMillions ? ['value' => number_format($v / 1e6, 2), 'unit' => 'ล้านบาท'] : $fmt($v, $unit);
                    @endphp
                    @if ($b['type'] === 'bars')
                        @foreach ($pairs as $p)
                            <div class="ig2-bar"><span class="nm">{{ $p[0] }}</span><span class="tr"><span class="fl" style="display:block;width:{{ max(2, round(abs($p[1]) / $max * 100)) }}%"></span></span><span class="vl">{{ $show($p[1])['value'] }}<small style="font-weight:600"> {{ $show($p[1])['unit'] }}</small></span></div>
                        @endforeach
                    @else
                        <ol class="ig2-list">
                            @foreach ($pairs as $i => $p)
                                <li><span class="rk">{{ $i + 1 }}</span><span>{{ $p[0] }}</span><span class="vl">{{ $show($p[1])['value'] }} <small style="font-weight:600">{{ $show($p[1])['unit'] }}</small></span></li>
                            @endforeach
                        </ol>
                    @endif
                @elseif ($b['type'] === 'highlights')
                    <ol class="ig2-hl">@foreach ($bullets as $x)<li>{{ $x['text'] }}</li>@endforeach</ol>
                @elseif ($b['type'] === 'text')
                    <p class="ig2-text">{{ $b['text'] }}</p>
                @endif
            </section>
        @endforeach
    </div>
    <div class="ig2-src">ข้อมูล: {{ $dashboard->dataset->name }}{{ $dashboard->dataset->period_label ? ' · งวด '.$dashboard->dataset->period_label : '' }} · สร้างด้วย MSC Report</div>
</div>
<script>window.MSC_IG2 = { theme: @json($spec['theme'] ?? 'org'), charts: @json($charts), width: {{ $igW }} };</script>
