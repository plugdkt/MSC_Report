@extends('layouts.app')

@section('title', 'ชุดรหัส '.$set->name)

@section('body')
<main>
    <div class="container">
        <p style="margin:0 0 .4rem"><a href="{{ route('admin.codes.index') }}">‹ ตารางรหัสทั้งหมด</a></p>
        <div class="page-head">
            <div>
                <h1>ชุดรหัส: {{ $set->name }}</h1>
                <p class="muted" style="margin:.2rem 0 0">{{ $values->count() }} รหัส · ผูกกับคอลัมน์ในข้อมูลที่นำเข้าแล้ว {{ $linkedColumns }} คอลัมน์</p>
            </div>
        </div>

        @include('admin.codes._flash')

        @if ($unknown)
            <div class="card" style="margin-bottom:1.2rem;border-color:var(--accent)">
                <h2 style="font-size:1.15rem">รหัสที่พบในข้อมูลแต่ยังไม่มีชื่อ ({{ count($unknown) }})</h2>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>รหัส</th><th>พบ</th><th>ตั้งชื่อ</th></tr></thead>
                        <tbody>
                            @foreach ($unknown as $code => $n)
                                <tr>
                                    <td><strong>{{ $code }}</strong></td>
                                    <td class="muted">{{ number_format($n) }} แถว</td>
                                    <td>
                                        <form class="inline-form" method="POST" action="{{ route('admin.codes.values.store', $set) }}">
                                            @csrf
                                            <input type="hidden" name="code" value="{{ $code }}">
                                            <input type="text" name="label" required placeholder="ชื่อของรหัส {{ $code }}" aria-label="ชื่อของรหัส {{ $code }}">
                                            <button class="btn btn-outline" type="submit">เพิ่ม</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="card" style="margin-bottom:1.2rem">
            <h2 style="font-size:1.15rem">รหัสและชื่อ</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th style="width:10rem">รหัส</th><th>ชื่อที่แสดงในรายงาน</th><th style="width:1%"></th></tr></thead>
                    <tbody>
                        @forelse ($values as $v)
                            <tr>
                                <td><strong>{{ $v->code }}</strong></td>
                                <td>
                                    <form class="inline-form" method="POST" action="{{ route('admin.codes.values.update', [$set, $v]) }}">
                                        @csrf @method('PUT')
                                        <input type="text" name="label" value="{{ $v->label }}" required style="flex:1;min-width:14rem" aria-label="ชื่อของรหัส {{ $v->code }}">
                                        <button class="btn btn-ghost" type="submit">บันทึก</button>
                                    </form>
                                </td>
                                <td>
                                    <form method="POST" action="{{ route('admin.codes.values.destroy', [$set, $v]) }}" onsubmit="return confirm('ลบรหัส {{ $v->code }}?')">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-ghost" type="submit">ลบ</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="muted">ยังไม่มีรหัส — เพิ่มทีละรายการด้านล่าง หรือนำเข้าจากไฟล์</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <form class="form-row" method="POST" action="{{ route('admin.codes.values.store', $set) }}" style="margin-top:1rem">
                @csrf
                <label>รหัส<input type="text" name="code" required></label>
                <label style="flex:1">ชื่อ<input type="text" name="label" required></label>
                <button class="btn btn-primary" type="submit">เพิ่มรหัส</button>
            </form>
            <form class="form-row" method="POST" action="{{ route('admin.codes.import', $set) }}" enctype="multipart/form-data" style="margin-top:1rem;border-top:1px solid var(--border);padding-top:1rem">
                @csrf
                <label>นำเข้าจาก Excel/CSV (คอลัมน์แรก = รหัส, คอลัมน์ที่สอง = ชื่อ)<input type="file" name="file" accept=".xlsx,.xls,.csv" required></label>
                <button class="btn btn-outline" type="submit">นำเข้า</button>
            </form>
        </div>

        <div class="grid-2">
            <div class="card">
                <h2 style="font-size:1.15rem">กฎจัดกลุ่ม</h2>
                <p class="muted" style="font-size:.92rem">ใช้รวมค่าเป็นกลุ่มใหญ่ในรายงาน เช่น ค่าที่ขึ้นต้นด้วย "3." = งบลงทุน</p>
                <ul class="quality" style="margin-bottom:1rem">
                    @forelse ($rules as $rule)
                        <li class="q-info"><span class="ic">↳</span><span style="flex:1">{{ $ruleTypes[$rule->match_type] }} <code>{{ $rule->pattern }}</code> → <strong>{{ $rule->group_label }}</strong></span>
                            <form method="POST" action="{{ route('admin.codes.rules.destroy', [$set, $rule]) }}">@csrf @method('DELETE')<button class="btn btn-ghost" type="submit" style="min-height:2rem;padding:.1rem .7rem">ลบ</button></form>
                        </li>
                    @empty
                        <li class="muted">ยังไม่มีกฎ</li>
                    @endforelse
                </ul>
                <form class="form-row" method="POST" action="{{ route('admin.codes.rules.store', $set) }}">
                    @csrf
                    <label>แบบ<select name="match_type">@foreach ($ruleTypes as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                    <label>ค่า<input type="text" name="pattern" required style="width:7rem"></label>
                    <label>ชื่อกลุ่ม<input type="text" name="group_label" required></label>
                    <button class="btn btn-outline" type="submit">เพิ่มกฎ</button>
                </form>
            </div>
            <div class="card">
                <h2 style="font-size:1.15rem">ตั้งค่าชุดรหัส</h2>
                <form method="POST" action="{{ route('admin.codes.update', $set) }}" style="display:grid;gap:.7rem">
                    @csrf @method('PUT')
                    <label class="muted" style="display:grid;gap:.2rem;font-weight:600">ชื่อชุดรหัส<input type="text" name="name" value="{{ $set->name }}" required></label>
                    <label class="muted" style="display:grid;gap:.2rem;font-weight:600">คำอธิบาย<input type="text" name="description" value="{{ $set->description }}"></label>
                    <label class="muted" style="display:grid;gap:.2rem;font-weight:600">คำในหัวคอลัมน์ที่จับคู่อัตโนมัติ (regex)<input type="text" name="column_pattern" value="{{ $set->column_pattern }}"></label>
                    <div><button class="btn btn-primary" type="submit">บันทึก</button></div>
                </form>
            </div>
        </div>
    </div>
</main>
@endsection
