@extends('layouts.app')

@section('title', 'ตารางรหัส')

@section('body')
<main>
    <div class="container">
        <div class="page-head">
            <div>
                <h1>ตารางรหัสอ้างอิง</h1>
                <p class="muted" style="margin:.2rem 0 0">ตั้งชื่อให้รหัสในไฟล์ (เช่น กองทุน 20200) เพื่อให้รายงานแสดงชื่อที่ผู้บริหารอ่านเข้าใจ</p>
            </div>
        </div>

        @include('admin.codes._flash')

        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ชุดรหัส</th><th>จับคู่กับหัวคอลัมน์</th><th>จำนวนรหัส</th><th>กฎจัดกลุ่ม</th><th>รหัสที่ยังไม่มีชื่อ</th></tr></thead>
                    <tbody>
                        @foreach ($sets as $s)
                            <tr>
                                <td><a href="{{ route('admin.codes.show', $s) }}"><strong>{{ $s->name }}</strong></a><br><span class="muted" style="font-size:.88rem">{{ $s->description }}</span></td>
                                <td><code>{{ $s->column_pattern ?: '—' }}</code></td>
                                <td>{{ number_format($s->values_count) }}</td>
                                <td>{{ $s->group_rules_count ?: '—' }}</td>
                                <td>@if ($unknown[$s->id] > 0)<span class="badge badge-admin">{{ $unknown[$s->id] }} รหัส</span>@else — @endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <details class="box" style="margin-top:1.2rem">
            <summary>เพิ่มชุดรหัสใหม่</summary>
            <form method="POST" action="{{ route('admin.codes.store') }}" class="form-row" style="margin-top:.8rem">
                @csrf
                <label>รหัสชุด (ภาษาอังกฤษ)<input type="text" name="key" required pattern="[A-Za-z0-9_\-]+" placeholder="เช่น project"></label>
                <label>ชื่อชุดรหัส<input type="text" name="name" required placeholder="เช่น โครงการ"></label>
                <label>คำในหัวคอลัมน์ (regex)<input type="text" name="column_pattern" placeholder="เช่น ^โครงการ$"></label>
                <button class="btn btn-primary" type="submit">สร้าง</button>
            </form>
        </details>
    </div>
</main>
@endsection
