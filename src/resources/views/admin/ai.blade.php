@extends('layouts.app')

@section('title', 'ตั้งค่า AI')

@section('body')
<main>
    <div class="container" style="max-width:62rem">
        <div class="page-head">
            <div>
                <h1>ตั้งค่า AI ขององค์กร</h1>
                <p class="muted" style="margin:.2rem 0 0">ผู้ใช้แต่ละคนใส่ API Key ของตัวเอง — Base URL และโมเดลกำหนดที่นี่ที่เดียว</p>
            </div>
            <form method="POST" action="{{ route('admin.ai.test') }}">@csrf<button class="btn btn-outline" type="submit">ทดสอบการเชื่อมต่อ</button></form>
        </div>

        @include('admin.codes._flash')
        @if (session('error'))
            <div class="alert alert-error" role="alert">{{ session('error') }}</div>
        @endif

        <form class="card" method="POST" action="{{ route('admin.ai.update') }}" style="margin-bottom:1.2rem">
            @csrf @method('PUT')
            <div class="grid-2">
                <label class="muted" style="display:grid;gap:.2rem;font-weight:600">รูปแบบ API
                    <select name="format">
                        <option value="openai" @selected($config->get('format') === 'openai')>OpenAI-compatible (/chat/completions)</option>
                        <option value="anthropic" @selected($config->get('format') === 'anthropic')>Anthropic-compatible (/messages)</option>
                    </select>
                </label>
                <label class="muted" style="display:grid;gap:.2rem;font-weight:600">วิธีส่ง key
                    <select name="auth_style">
                        <option value="bearer" @selected($config->get('auth_style') === 'bearer')>Authorization: Bearer</option>
                        <option value="x-api-key" @selected($config->get('auth_style') === 'x-api-key')>x-api-key</option>
                        <option value="api-key" @selected($config->get('auth_style') === 'api-key')>api-key</option>
                    </select>
                </label>
                <label class="muted" style="display:grid;gap:.2rem;font-weight:600;grid-column:1/-1">Base URL (รวม /v1 ถ้ามี)
                    <input type="text" name="base_url" value="{{ $config->get('base_url') }}" placeholder="https://ai.example.ac.th/v1">
                </label>
                <label class="muted" style="display:grid;gap:.2rem;font-weight:600">โมเดลหลัก (ถามคำถาม/วางแผน)
                    <input type="text" name="model_smart" value="{{ $config->get('model_smart') }}">
                </label>
                <label class="muted" style="display:grid;gap:.2rem;font-weight:600">โมเดลเบา (งานสั้น — ว่าง = ใช้โมเดลหลัก)
                    <input type="text" name="model_fast" value="{{ $config->get('model_fast') }}">
                </label>
                <label class="muted" style="display:grid;gap:.2rem;font-weight:600">Timeout (วินาที)
                    <input type="text" inputmode="numeric" name="timeout" value="{{ $config->get('timeout') }}">
                </label>
                <label class="check" style="align-self:end"><input type="hidden" name="json_mode" value="0"><input type="checkbox" name="json_mode" value="1" @checked($config->get('json_mode'))> รองรับ JSON mode (response_format)</label>
            </div>

            <h2 style="font-size:1.1rem;margin-top:1.4rem">Key กลางขององค์กร (ใช้สำรองเมื่อผู้ใช้ไม่มี key)</h2>
            <div class="grid-2">
                <label class="muted" style="display:grid;gap:.2rem;font-weight:600">Key กลาง {{ $sharedSet ? '(ตั้งไว้แล้ว — เว้นว่างเพื่อคงเดิม)' : '' }}
                    <input type="password" name="shared_key" autocomplete="off" style="font:inherit;padding:.35rem .6rem;border:1px solid var(--border);border-radius:8px;min-height:2.5rem">
                </label>
                <label class="muted" style="display:grid;gap:.2rem;font-weight:600">เพดาน token ต่อคนต่อเดือน
                    <input type="text" inputmode="numeric" name="shared_monthly_cap" value="{{ \App\Models\Setting::get('ai.shared_monthly_cap', 20000) }}">
                </label>
                <label class="check"><input type="hidden" name="shared_enabled" value="0"><input type="checkbox" name="shared_enabled" value="1" @checked(\App\Models\Setting::get('ai.shared_enabled'))> เปิดใช้ key กลาง</label>
                @if ($sharedSet)<label class="check"><input type="checkbox" name="shared_key_clear" value="1"> ลบ key กลาง</label>@endif
            </div>
            <div style="margin-top:1.2rem"><button class="btn btn-primary" type="submit">บันทึก</button></div>
        </form>

        <div class="card" style="margin-bottom:1.2rem">
            <h2 style="font-size:1.2rem">การใช้ token เดือนนี้ (ทั้งองค์กร)</h2>
            <p style="margin:0">key ส่วนตัว <b>{{ number_format($usage['personal']->tokens ?? 0) }}</b> token ({{ $usage['personal']->requests ?? 0 }} ครั้ง)
                · key กลาง <b>{{ number_format($usage['shared']->tokens ?? 0) }}</b> token ({{ $usage['shared']->requests ?? 0 }} ครั้ง)
                · ประหยัดจาก cache ราว <b>{{ number_format($cacheSaved) }}</b> token</p>
        </div>

        <div class="card">
            <h2 style="font-size:1.2rem">API Key ของผู้ใช้</h2>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ผู้ใช้</th><th>Key</th><th>สถานะ</th><th>ใช้ล่าสุด</th><th></th></tr></thead>
                    <tbody>
                        @forelse ($credentials as $c)
                            <tr>
                                <td>{{ $c->user->name }}<br><span class="muted" style="font-size:.88rem">{{ $c->user->username }}</span></td>
                                <td>{{ $c->maskedKey() }}</td>
                                <td>{{ $c->statusLabel() }}</td>
                                <td>{{ \App\Support\Thai::date($c->last_used_at, true) }}</td>
                                <td>@if ($c->status !== 'revoked')<form method="POST" action="{{ route('admin.ai.revoke', $c) }}" onsubmit="return confirm('ยกเลิก key ของผู้ใช้นี้?')">@csrf<button class="btn btn-ghost" type="submit">ยกเลิก key</button></form>@endif</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="muted">ยังไม่มีผู้ใช้เพิ่ม key</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>
@endsection
