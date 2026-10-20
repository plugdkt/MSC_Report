@extends('layouts.app')

@section('title', 'ประวัติเวอร์ชัน')

@section('body')
<main>
    <div class="container" style="max-width:56rem">
        <p style="margin:0 0 .4rem"><a href="{{ route('dashboards.show', $dashboard) }}">‹ กลับไปที่รายงาน</a></p>
        <h1 style="font-size:1.8rem">ประวัติเวอร์ชัน: {{ $dashboard->title }}</h1>
        <p class="muted">ทุกครั้งที่บันทึกจะเก็บเป็นเวอร์ชันใหม่ ย้อนกลับได้เสมอ (การย้อนกลับจะสร้างเวอร์ชันใหม่ ไม่ลบของเดิม)</p>
        <div class="card">
            <table>
                <thead><tr><th>เวอร์ชัน</th><th>เมื่อ</th><th>รายละเอียด</th><th></th></tr></thead>
                <tbody>
                    @foreach ($versions as $v)
                        <tr>
                            <td><b>{{ $v->version }}</b> @if ($v->id === $dashboard->current_version_id)<span class="badge badge-good">ปัจจุบัน</span>@endif</td>
                            <td style="white-space:nowrap">{{ \App\Support\Thai::date($v->created_at, true) }}</td>
                            <td>{{ $v->note }} <span class="muted">· {{ count($v->spec['widgets']) }} ส่วน{{ $v->executive_summary ? ' · มีบทสรุป' : '' }}</span></td>
                            <td>
                                @if ($v->id !== $dashboard->current_version_id)
                                    <form method="POST" action="{{ route('dashboards.revert', [$dashboard, $v]) }}">@csrf<button class="btn btn-ghost" type="submit">ย้อนกลับเป็นเวอร์ชันนี้</button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</main>
@endsection
