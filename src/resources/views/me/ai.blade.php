@extends('layouts.app')

@section('title', 'AI ของฉัน')

@section('body')
<main>
    <div class="container" style="max-width:60rem">
        <div class="page-head">
            <div>
                <h1>AI ของฉัน</h1>
                <p class="muted" style="margin:.2rem 0 0">ใช้ API Key ของ AI ภายในองค์กรที่คุณได้รับ — token ถูกหักจากโควต้าของคุณเอง</p>
            </div>
        </div>

        @include('admin.codes._flash')
        @if (session('error'))
            <div class="alert alert-error" role="alert">{{ session('error') }}</div>
        @endif

        @unless ($configured)
            <div class="alert alert-error" role="alert">ผู้ดูแลระบบยังไม่ได้ตั้งค่า AI ขององค์กร — ตอนนี้ใช้ได้เฉพาะโหมดไม่ใช้ AI</div>
        @endunless

        <div class="card" style="margin-bottom:1.2rem">
            <h2 style="font-size:1.2rem">API Key</h2>
            @if ($credential)
                <p style="margin:.2rem 0 .8rem">
                    <span style="font-size:1.3rem;font-weight:700;letter-spacing:.05em">{{ $credential->maskedKey() }}</span>
                    <span @class(['badge', 'badge-good' => $credential->status === 'active', 'badge-admin' => $credential->status !== 'active'])>{{ $credential->statusLabel() }}</span>
                </p>
                <p class="muted" style="margin:0 0 1rem;font-size:.92rem">
                    ทดสอบล่าสุด {{ \App\Support\Thai::date($credential->last_verified_at, true) }}
                    · ใช้ล่าสุด {{ \App\Support\Thai::date($credential->last_used_at, true) }}
                    @if ($credential->last_error)<br>ข้อผิดพลาดล่าสุด: {{ $credential->last_error }}@endif
                </p>
            @else
                <p class="muted">ยังไม่มี API Key — ถ้าไม่ใส่ ระบบยังสร้างรายงานได้ในโหมดไม่ใช้ AI</p>
            @endif

            <form method="POST" action="{{ route('me.ai.store') }}" class="form-row">
                @csrf
                <label style="flex:1;min-width:16rem">{{ $credential ? 'เปลี่ยนเป็น key ใหม่' : 'วาง API Key' }}
                    <input type="password" name="api_key" autocomplete="off" required placeholder="sk-…" style="font:inherit;padding:.35rem .6rem;border:1px solid var(--border);border-radius:8px;min-height:2.5rem">
                </label>
                <button class="btn btn-primary" type="submit">ทดสอบและบันทึก</button>
            </form>
            @error('api_key')<div class="field-error">{{ $message }}</div>@enderror
            <p class="muted" style="font-size:.88rem;margin:.6rem 0 0">🔒 ระบบทดสอบ key ก่อนบันทึก เก็บแบบเข้ารหัส และไม่แสดงค่าเต็มอีก (ผู้ดูแลระบบก็มองไม่เห็น)</p>

            @if ($credential)
                <div class="grid-2" style="margin-top:1.2rem;border-top:1px solid var(--border);padding-top:1rem">
                    <form method="POST" action="{{ route('me.ai.budget') }}" class="form-row">
                        @csrf @method('PUT')
                        <label>งบ token ต่อเดือนในระบบนี้ (ว่าง = ไม่จำกัด)
                            <input type="text" inputmode="numeric" name="monthly_token_budget" value="{{ $credential->monthly_token_budget }}" placeholder="เช่น 50000">
                        </label>
                        <button class="btn btn-outline" type="submit">บันทึกงบ</button>
                    </form>
                    <form method="POST" action="{{ route('me.ai.destroy') }}" style="align-self:end;text-align:right" onsubmit="return confirm('ลบ API Key?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-ghost" type="submit">ลบ API Key</button>
                    </form>
                </div>
            @endif
        </div>

        @if ($credential?->quota)
            <div class="card" style="margin-bottom:1.2rem">
                <h2 style="font-size:1.2rem">โควต้ารายวันจาก UP AI Connect</h2>
                <p class="muted" style="margin:0 0 .6rem;font-size:.92rem">ตัวเลขล่าสุดที่ gateway แจ้งกลับมา (รีเซ็ตทุกวัน แยกตามโมเดล)</p>
                <table>
                    <thead><tr><th>โมเดล</th><th>ใช้ไปวันนี้</th><th>คงเหลือ</th><th>โควต้า/วัน</th><th>อัปเดต</th></tr></thead>
                    <tbody>
                        @foreach ($credential->quota as $model => $q)
                            <tr>
                                <td>{{ $model }}</td>
                                <td>{{ number_format($q['used']) }}</td>
                                <td><b>{{ number_format($q['remaining']) }}</b> <span class="muted">({{ $q['quota'] ? round($q['remaining'] / $q['quota'] * 100) : 0 }}%)</span></td>
                                <td>{{ number_format($q['quota']) }}</td>
                                <td style="white-space:nowrap">{{ \App\Support\Thai::date($q['at'] ?? null, true) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="card">
            <h2 style="font-size:1.2rem">การใช้ token เดือนนี้</h2>
            <div class="tiles" style="grid-template-columns:repeat(3,1fr)">
                <div class="tile"><div class="l">key ของฉัน</div><div class="v">{{ number_format($monthPersonal) }}</div></div>
                <div class="tile"><div class="l">key กลางขององค์กร</div><div class="v">{{ number_format($monthShared) }}</div></div>
                <div class="tile"><div class="l">งบที่ตั้งไว้</div><div class="v">{{ $credential?->monthly_token_budget ? number_format($credential->monthly_token_budget) : '—' }}</div></div>
            </div>
            @if ($byStage->isNotEmpty())
                @php($stageNames = ['clarify' => 'ถามคำถาม', 'plan' => 'วางแผนรายงาน', 'narrative' => 'เขียนบทสรุป', 'refine' => 'แก้ไขรายงาน', 'test' => 'ทดสอบ key'])
                <table>
                    <thead><tr><th>ขั้นตอน</th><th>เรียก AI</th><th>ใช้ซ้ำจาก cache</th><th>token</th></tr></thead>
                    <tbody>
                        @foreach ($byStage as $s)
                            <tr><td>{{ $stageNames[$s->stage] ?? $s->stage }}</td><td>{{ $s->calls }}</td><td>{{ (int) $s->cached }}</td><td>{{ number_format($s->tokens) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="muted" style="margin:0">ยังไม่มีการใช้ AI เดือนนี้</p>
            @endif
        </div>
    </div>
</main>
@endsection
