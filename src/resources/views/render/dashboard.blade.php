@extends('layouts.render')

@section('title', $spec['title'])

@php($summaryBullets = $summary['bullets'] ?? [])

@section('body')
<div class="board-wrap">
    <div class="board-title">
        <h1>{{ $spec['title'] }}</h1>
        <p>{{ $spec['subtitle'] ?? '' }}{{ $dataset->period_label ? ' · งวด '.$dataset->period_label : '' }}</p>
    </div>
    @if ($summaryBullets)
        <section class="card summary-card" style="box-shadow:none;margin-bottom:16px">
            <div class="summary-head"><h2>✦ บทสรุปสำหรับผู้บริหาร</h2></div>
            <ol>@foreach ($summaryBullets as $b)<li>{{ $b['text'] }}</li>@endforeach</ol>
        </section>
    @endif
    <div class="dash-board" id="dash"></div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var spec = @json($spec), data = @json($computed), root = document.getElementById('dash');
    spec.widgets.forEach(function (w) {
        var el = document.createElement('section');
        el.className = 'card dash-item dash-' + w.type;
        el.style.gridColumn = (w.layout.x + 1) + ' / span ' + w.layout.w;
        el.style.gridRow = (w.layout.y + 1) + ' / span ' + w.layout.h;
        root.appendChild(el);
        MscDashboard.renderWidget(el, w, data[w.id], spec.theme, { renderer: 'svg' });
    });
    MscDashboard.markReady();
})();
</script>
@endpush
