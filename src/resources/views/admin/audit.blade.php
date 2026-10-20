@extends('layouts.app')

@section('title', 'บันทึกกิจกรรม')

@use('App\Support\Thai')

@section('body')
<main>
    <div class="container">
        <div class="page-head"><div><h1>บันทึกกิจกรรม</h1><p class="muted" style="margin:.2rem 0 0">เหตุการณ์สำคัญของระบบ — ไม่เก็บ API Key หรือข้อมูลดิบในไฟล์</p></div></div>
        <form method="GET" class="card form-row" style="margin-bottom:1rem">
            <label>ค้นหา (ผู้ใช้/รายละเอียด/IP)<input type="text" name="q" value="{{ $f['q'] ?? '' }}"></label>
            <label>เหตุการณ์<select name="action"><option value="">ทั้งหมด</option>@foreach ($actions as $k => $l)<option value="{{ $k }}" @selected(($f['action'] ?? '') === $k)>{{ $l }}</option>@endforeach</select></label>
            <label>ตั้งแต่<input type="date" name="from" value="{{ $f['from'] ?? '' }}"></label>
            <label>ถึง<input type="date" name="to" value="{{ $f['to'] ?? '' }}"></label>
            <button class="btn btn-outline" type="submit">กรอง</button>
        </form>
        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead><tr><th>เวลา</th><th>ผู้ใช้</th><th>เหตุการณ์</th><th>รายละเอียด</th><th>IP</th></tr></thead>
                    <tbody>
                        @forelse ($logs as $log)
                            <tr>
                                <td style="white-space:nowrap">{{ Thai::date($log->created_at, true) }}</td>
                                <td>{{ $log->username ?? '—' }}</td>
                                <td><span @class(['badge', 'badge-admin' => str_starts_with($log->action, 'admin.') || str_contains($log->action, 'failed')])>{{ $log->actionLabel() }}</span></td>
                                <td>{{ $log->summary }}@if ($log->meta)<br><span class="muted" style="font-size:.82rem">{{ collect($log->meta)->map(fn ($v, $k) => $k.': '.(is_scalar($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE)))->implode(' · ') }}</span>@endif</td>
                                <td class="muted" style="font-size:.88rem">{{ $log->ip }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">ไม่พบกิจกรรม</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            {{ $logs->links() }}
        </div>
    </div>
</main>
@endsection
