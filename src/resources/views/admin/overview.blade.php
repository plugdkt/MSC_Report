@extends('layouts.app')

@section('title', 'ภาพรวมระบบ')

@use('App\Support\Thai')

@section('body')
<main>
    <div class="container">
        <div class="page-head"><div><h1>ภาพรวมระบบ</h1><p class="muted" style="margin:.2rem 0 0">สถิติการใช้งานและสถานะคิวงาน</p></div></div>
        <div class="tiles">
            @foreach ($stats as $label => $value)
                <div class="tile"><div class="l">{{ $label }}</div><div class="v">{{ number_format($value) }}</div></div>
            @endforeach
        </div>
        <div class="grid-2">
            <div class="card">
                <h2 style="font-size:1.15rem">คิวงาน</h2>
                <p style="margin:0">รอประมวลผล <b>{{ $queue['pending'] }}</b> งาน · ล้มเหลว <b @class(['neg' => $queue['failed'] > 0])>{{ $queue['failed'] }}</b> งาน</p>
                @if ($queue['pending'] > 5)<p class="muted" style="margin:.4rem 0 0">คิวสะสมเกิน 5 งาน — ตรวจว่า queue worker ยังทำงานอยู่</p>@endif
                <h2 style="font-size:1.15rem;margin-top:1.2rem">ผู้ใช้ AI สูงสุดเดือนนี้</h2>
                <table><tbody>
                    @forelse ($topUsers as $u)<tr><td>{{ $u->name }}<br><span class="muted" style="font-size:.85rem">{{ $u->username }}</span></td><td style="text-align:right">{{ number_format($u->tokens) }} token</td></tr>
                    @empty<tr><td class="muted">ยังไม่มีการใช้ AI เดือนนี้</td></tr>@endforelse
                </tbody></table>
            </div>
            <div class="card">
                <h2 style="font-size:1.15rem">กิจกรรมล่าสุด <a href="{{ route('admin.audit.index') }}" style="font-size:.9rem;font-weight:400">ดูทั้งหมด ›</a></h2>
                <ul class="quality">
                    @forelse ($recent as $log)
                        <li class="q-info"><span class="ic">•</span><span><b>{{ $log->actionLabel() }}</b> · {{ $log->username ?? '—' }}<br><span class="muted" style="font-size:.85rem">{{ Thai::date($log->created_at, true) }}{{ $log->summary ? ' · '.$log->summary : '' }}</span></span></li>
                    @empty<li class="muted">ยังไม่มีกิจกรรม</li>@endforelse
                </ul>
            </div>
        </div>
    </div>
</main>
@endsection
