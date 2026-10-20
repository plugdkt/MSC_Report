@extends('layouts.app')

@section('title', 'เลือกชีต')

@php
    $kindLabels = [
        'title' => 'หัวรายงาน', 'header' => 'หัวตาราง', 'data' => '',
        'subtotal' => 'ยอดรวมย่อย', 'total' => 'ยอดรวม', 'check' => 'ตรวจสอบ', 'header_repeat' => 'หัวซ้ำ', 'footer' => 'ท้ายตาราง',
    ];
@endphp

@section('body')
<main>
    <div class="container">
        @include('datasets._steps', ['current' => 2])
        <div class="page-head">
            <div>
                <h1>เลือกชีตที่เป็นข้อมูล</h1>
                <p class="muted" style="margin:.2rem 0 0">📄 {{ $dataset->original_filename }} · {{ count($sheets) }} ชีต</p>
            </div>
        </div>

        @if ($error)
            <div class="alert alert-error" role="alert">{{ $error }}</div>
        @endif

        <div class="sheet-layout">
            <nav class="sheet-list" aria-label="ชีตในไฟล์">
                @foreach ($sheets as $s)
                    <a href="{{ route('datasets.sheets', [$dataset, 'sheet' => $s['name']]) }}" @class(['sheet-item', 'active' => $s['name'] === $selected['name']])>
                        <b>{{ $s['name'] }} @if ($s['recommended']) <span class="badge badge-good">แนะนำ</span> @endif</b>
                        <small>ข้อมูล {{ number_format($s['data_rows']) }} แถว · {{ count($s['columns']) }} คอลัมน์</small>
                        @if ($s['subtotal_rows'] + $s['total_rows'] + $s['repeat_rows'] > 0)
                            <small>ตัดแถวรวม/หัวซ้ำ {{ $s['subtotal_rows'] + $s['total_rows'] + $s['repeat_rows'] }} แถว</small>
                        @endif
                    </a>
                @endforeach
            </nav>

            <section class="card" aria-label="ตัวอย่างชีต {{ $selected['name'] }}">
                <h2 style="font-size:1.3rem;margin-bottom:.2rem">ชีต "{{ $selected['name'] }}"</h2>
                @if ($selected['recommended'])
                    <p style="margin:0 0 .6rem" class="muted">✅ ระบบแนะนำชีตนี้ เพราะเป็นตารางข้อมูลเรียบ ไม่มีแถวยอดรวมแทรก</p>
                @elseif ($selected['subtotal_rows'] > 0)
                    <p style="margin:0 0 .6rem" class="muted">ชีตนี้มีแถวยอดรวมแทรก — ระบบตัดออกให้ แต่ชีตที่ "แนะนำ" มักให้ผลแม่นกว่า</p>
                @endif

                @if ($selected['title_lines'])
                    <p style="margin:.2rem 0 .6rem"><b>หัวรายงานที่พบ:</b> {{ implode(' · ', $selected['title_lines']) }}</p>
                @endif

                <div class="legend-row">
                    <span><span class="kind kind-header">หัวตาราง</span> ใช้เป็นชื่อคอลัมน์</span>
                    <span><span class="kind kind-x">ยอดรวม</span> ไม่นับในการคำนวณ</span>
                    <span>แสดง {{ count($selected['preview']) }} แถวแรกจาก {{ number_format($selected['highest_row']) }}</span>
                </div>

                <div class="table-wrap" style="max-height:30rem;overflow:auto;border:1px solid var(--border);border-radius:10px">
                    <table class="preview-table">
                        <thead>
                            <tr><th class="rn">แถว</th><th class="k"></th>
                                @foreach ($selected['column_letters'] as $letter)<th>{{ $letter }}</th>@endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($selected['preview'] as $p)
                                <tr class="k-{{ $p['kind'] }}">
                                    <td class="rn">{{ $p['row'] }}</td>
                                    <td class="k">
                                        @if ($kindLabels[$p['kind']] ?? '')
                                            <span @class(['kind', 'kind-header' => $p['kind'] === 'header', 'kind-x' => ! in_array($p['kind'], ['header', 'title'])])>{{ $kindLabels[$p['kind']] }}</span>
                                        @endif
                                    </td>
                                    @foreach ($p['cells'] as $cell)<td title="{{ $cell }}">{{ $cell }}</td>@endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <form method="GET" action="{{ route('datasets.sheets', $dataset) }}" class="form-row" style="margin-top:1rem">
                    <input type="hidden" name="sheet" value="{{ $selected['name'] }}">
                    <label>หัวตารางเริ่มแถว
                        <select name="header_start">@for ($i = 1; $i <= 20; $i++)<option value="{{ $i }}" @selected($i === $selected['header_start'])>{{ $i }}</option>@endfor</select>
                    </label>
                    <label>ถึงแถว
                        <select name="header_end">@for ($i = 1; $i <= 23; $i++)<option value="{{ $i }}" @selected($i === $selected['header_end'])>{{ $i }}</option>@endfor</select>
                    </label>
                    <button class="btn btn-ghost" type="submit">ดูตัวอย่างใหม่</button>
                    <span class="muted" style="font-size:.9rem">ปรับเฉพาะเมื่อระบบเลือกหัวตารางผิด</span>
                </form>

                <form method="POST" action="{{ route('datasets.import', $dataset) }}" style="margin-top:1.2rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:1rem">
                    @csrf
                    <input type="hidden" name="sheet" value="{{ $selected['name'] }}">
                    <input type="hidden" name="header_start" value="{{ $selected['header_start'] }}">
                    <input type="hidden" name="header_end" value="{{ $selected['header_end'] }}">
                    <span><b>{{ number_format($selected['data_rows']) }}</b> แถวข้อมูล · <b>{{ count($selected['columns']) }}</b> คอลัมน์</span>
                    <button class="btn btn-primary btn-lg" type="submit">นำเข้าชีตนี้</button>
                </form>
            </section>
        </div>
    </div>
</main>
@endsection
