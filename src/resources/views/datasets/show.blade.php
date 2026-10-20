@extends('layouts.app')

@section('title', $dataset->name)

@use('App\Support\Thai')

@if ($dataset->status === 'importing')
    @push('head')<meta http-equiv="refresh" content="2">@endpush
@endif

@section('body')
<main>
    <div class="container">
        @include('datasets._steps', ['current' => 3])

        <div class="page-head">
            <div>
                <h1>{{ $dataset->name }}</h1>
                <p class="muted" style="margin:.2rem 0 0">
                    📄 {{ $dataset->original_filename }}
                    @if ($dataset->sheet_name) · ชีต "{{ $dataset->sheet_name }}" @endif
                    @if ($dataset->period_label) · งวด {{ $dataset->period_label }} @endif
                </p>
            </div>
            @if ($canEdit)
                <form method="POST" action="{{ route('datasets.destroy', $dataset) }}" onsubmit="return confirm('ลบข้อมูลชุดนี้และไฟล์ต้นฉบับ?')">
                    @csrf @method('DELETE')
                    <button class="btn btn-ghost" type="submit">ลบข้อมูลชุดนี้</button>
                </form>
            @endif
        </div>

        @if (session('status'))
            <div class="alert alert-ok" role="status">{{ session('status') }}</div>
        @endif

        @if ($dataset->status === 'importing')
            <div class="card empty">
                <div class="spinner" aria-hidden="true"></div>
                <h2 style="font-size:1.3rem">กำลังนำเข้าข้อมูล…</h2>
                <p class="muted">หน้านี้จะแสดงผลเองเมื่อเสร็จ</p>
            </div>
        @elseif ($dataset->status === 'failed')
            <div class="alert alert-error" role="alert">{{ $dataset->error_message }}</div>
            <a class="btn btn-outline" href="{{ route('datasets.sheets', $dataset) }}">กลับไปเลือกชีต</a>
        @else
            <div class="tiles">
                <div class="tile"><div class="l">แถวข้อมูล</div><div class="v">{{ number_format($dataset->row_count) }}</div></div>
                <div class="tile"><div class="l">คอลัมน์</div><div class="v">{{ $columns->where('is_hidden', false)->count() }}</div></div>
                <div class="tile"><div class="l">แถวที่ตัดออก</div><div class="v">{{ count($dataset->excluded_rows ?? []) }}</div></div>
                <div class="tile"><div class="l">นำเข้าเมื่อ</div><div class="v" style="font-size:1.15rem;padding-top:.35rem">{{ Thai::date($dataset->updated_at, true) }}</div></div>
            </div>

            <div class="card" style="margin-bottom:1.2rem">
                <h2 style="font-size:1.2rem">ข้อสังเกตจากการตรวจข้อมูล</h2>
                <ul class="quality">
                    @foreach ($dataset->quality ?? [] as $q)
                        <li class="q-{{ $q['level'] }}"><span class="ic" aria-hidden="true">{{ ['ok' => '✓', 'warn' => '!', 'info' => 'i'][$q['level']] ?? 'i' }}</span><span>{{ $q['message'] }}</span></li>
                    @endforeach
                </ul>
                @if ($dataset->excluded_rows)
                    <details class="box" style="margin-top:.9rem">
                        <summary>ดูแถวที่ตัดออก ({{ count($dataset->excluded_rows) }} แถว)</summary>
                        <div class="table-wrap" style="margin-top:.5rem">
                            <table class="preview-table">
                                <thead><tr><th class="rn">แถว</th><th>ชนิด</th><th>ข้อความในแถว</th></tr></thead>
                                <tbody>
                                    @foreach ($dataset->excluded_rows as $x)
                                        <tr><td class="rn">{{ $x['row'] }}</td><td>{{ $x['label'] }}</td><td style="white-space:normal">{{ $x['text'] }}</td></tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </details>
                @endif
            </div>

            <form class="card" method="POST" action="{{ route('datasets.columns.update', $dataset) }}">
                @csrf @method('PUT')
                <div class="page-head" style="margin-bottom:.6rem">
                    <div>
                        <h2 style="font-size:1.2rem;margin:0">คอลัมน์ในข้อมูล</h2>
                        <p class="muted" style="margin:.2rem 0 0;font-size:.95rem">ตั้งชื่อให้เข้าใจง่าย (ชื่อนี้จะแสดงในรายงาน) และตรวจชนิดข้อมูลก่อนส่งให้ AI</p>
                    </div>
                    @if ($canEdit)<button class="btn btn-primary" type="submit">บันทึก</button>@endif
                </div>
                <div class="table-wrap">
                    <table class="col-table">
                        <thead><tr><th>ชื่อในไฟล์</th><th>ชื่อที่แสดงในรายงาน</th><th>ชนิด</th><th>สรุปข้อมูล</th><th>ส่วนบุคคล</th><th>ซ่อน</th></tr></thead>
                        <tbody>
                            @foreach ($columns as $c)
                                @php($p = $c->profile ?? [])
                                <tr @class(['hidden-col' => $c->is_hidden])>
                                    <td>{{ $c->source_name ?: '—' }}</td>
                                    <td><input type="text" name="columns[{{ $c->slug }}][display_name]" value="{{ $c->display_name }}" @disabled(! $canEdit) aria-label="ชื่อที่แสดงของ {{ $c->source_name }}"></td>
                                    <td>
                                        <select name="columns[{{ $c->slug }}][type]" @disabled(! $canEdit) aria-label="ชนิดของ {{ $c->display_name }}">
                                            @foreach ($types as $key => $label)<option value="{{ $key }}" @selected($c->type() === $key)>{{ $label }}</option>@endforeach
                                        </select>
                                    </td>
                                    <td class="stat">
                                        @if ($c->isNumeric() && isset($p['sum']))
                                            รวม <b>{{ Thai::money($p['sum']) }}</b><br>ต่ำสุด {{ Thai::money($p['min']) }} · สูงสุด {{ Thai::money($p['max']) }}
                                        @elseif ($c->type() === 'date' && isset($p['min']))
                                            {{ Thai::date($p['min']) }} – {{ Thai::date($p['max']) }}
                                        @elseif (isset($p['top']) && $c->code_set_id)
                                            {{ $p['unique'] }} ค่า:
                                            @foreach (array_slice(array_keys($p['top']), 0, 3) as $code)
                                                @php($label = $codes->label($c->code_set_id, (string) $code) ?? $codes->group($c->code_set_id, (string) $code))
                                                <br>{{ mb_strimwidth((string) $code, 0, 34, '…') }}@if ($label) <span class="code-label">→ {{ $label }}</span>@endif
                                            @endforeach
                                            {{ $p['unique'] > 3 ? ' …' : '' }}
                                            <br><span class="badge" style="margin-top:.2rem">ชุดรหัส: {{ $c->codeSet?->name }}</span>
                                        @elseif (isset($p['top']))
                                            {{ $p['unique'] }} ค่า: {{ implode(', ', array_slice(array_keys($p['top']), 0, 4)) }}{{ $p['unique'] > 4 ? ' …' : '' }}
                                        @else
                                            {{ implode(', ', array_map(fn ($s) => mb_strimwidth($s, 0, 30, '…'), array_slice($p['samples'] ?? [], 0, 2))) }}
                                        @endif
                                        @if (($p['nulls'] ?? 0) > 0)<br>ว่าง {{ $p['nulls'] }} แถว@endif
                                    </td>
                                    <td><input type="hidden" name="columns[{{ $c->slug }}][is_pii]" value="0"><label class="check"><input type="checkbox" name="columns[{{ $c->slug }}][is_pii]" value="1" @checked($c->is_pii) @disabled(! $canEdit)><span class="sr-only">ข้อมูลส่วนบุคคล</span></label></td>
                                    <td><input type="hidden" name="columns[{{ $c->slug }}][is_hidden]" value="0"><label class="check"><input type="checkbox" name="columns[{{ $c->slug }}][is_hidden]" value="1" @checked($c->is_hidden) @disabled(! $canEdit)><span class="sr-only">ซ่อน</span></label></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </form>

            <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:1.4rem">
                <span style="display:flex;gap:.6rem;flex-wrap:wrap">
                    <a class="btn btn-outline" href="{{ route('datasets.sheets', $dataset) }}">‹ เลือกชีตใหม่</a>
                    <a class="btn btn-ghost" href="{{ route('datasets.excel-template', $dataset) }}">สร้างไฟล์ Excel สำหรับกรอกงวดถัดไป</a>
                </span>
                @if ($canEdit)
                    <div style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center;justify-content:flex-end">
                        @if ($ai['available'])
                            <form method="POST" action="{{ route('drafts.start', $dataset) }}">@csrf<input type="hidden" name="mode" value="rule"><button class="btn btn-ghost" type="submit">สร้างแบบไม่ใช้ AI</button></form>
                            <div style="text-align:right">
                                <a class="btn btn-primary btn-lg" href="{{ route('drafts.brief', $dataset) }}">ถัดไป: ให้ AI ช่วยสรุป ›</a>
                                <div class="muted" style="font-size:.85rem;margin-top:.2rem">อธิบายว่าอยากให้สรุปอะไร · ใช้ราว {{ number_format($estimate) }} token{{ $ai['source'] === 'shared' ? ' (key กลาง)' : '' }}</div>
                            </div>
                        @else
                            <span class="muted" style="max-width:24rem;font-size:.92rem;text-align:right">{{ $ai['reason'] }} · <a href="{{ route('me.ai') }}">ตั้งค่า AI ของฉัน</a></span>
                            <form method="POST" action="{{ route('drafts.start', $dataset) }}">@csrf<input type="hidden" name="mode" value="rule"><button class="btn btn-primary btn-lg" type="submit">ถัดไป: สร้างรายงาน (ไม่ใช้ AI) ›</button></form>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>
</main>
@endsection
