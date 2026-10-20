@extends('layouts.app')

@section('title', 'ตอบคำถามก่อนสรุป')

@if (in_array($draft->status, ['asking', 'planning'], true))
    @push('head')<meta http-equiv="refresh" content="2">@endpush
@endif

@section('body')
<main>
    <div class="container" style="max-width:56rem">
        @include('datasets._steps', ['current' => 4])
        <p class="muted" style="margin:0 0 .3rem">📄 {{ $dataset->name }}{{ $dataset->period_label ? ' · งวด '.$dataset->period_label : '' }}</p>

        @foreach ($draft->notices ?? [] as $notice)
            <div class="alert alert-error" role="status" style="background:#fff6e6;color:#7a5414;border-color:#f3d9a5">ℹ️ {{ $notice }}</div>
        @endforeach

        @if ($draft->status === 'asking')
            <div class="card empty">
                <div class="spinner" aria-hidden="true"></div>
                <h1 style="font-size:1.4rem">{{ $draft->mode === 'ai' ? 'AI กำลังอ่านข้อมูลของคุณ…' : 'กำลังเตรียมคำถาม…' }}</h1>
                <p class="muted">{{ $draft->mode === 'ai' ? 'ส่งให้ AI เฉพาะชื่อคอลัมน์ สถิติ และตัวอย่างที่ปิดบังข้อมูลส่วนบุคคลแล้ว' : 'ใช้คำถามมาตรฐานจากโครงสร้างข้อมูล (ไม่ใช้ AI)' }}</p>
            </div>
        @elseif ($draft->status === 'planning')
            <div class="card empty">
                <div class="spinner" aria-hidden="true"></div>
                <h1 style="font-size:1.4rem">{{ $draft->mode === 'ai' ? 'AI กำลังออกแบบรายงาน…' : 'กำลังสร้างรายงาน…' }}</h1>
                <p class="muted">ตัวเลขทุกตัวคำนวณจากข้อมูลจริงในระบบ</p>
            </div>
        @elseif ($draft->status === 'failed')
            <div class="alert alert-error" role="alert">{{ $draft->error_message }}</div>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap">
                <form method="POST" action="{{ route('drafts.retry', $draft) }}">@csrf<input type="hidden" name="mode" value="ai"><button class="btn btn-primary" type="submit">ลองใหม่ด้วย AI</button></form>
                <form method="POST" action="{{ route('drafts.retry', $draft) }}">@csrf<input type="hidden" name="mode" value="rule"><button class="btn btn-outline" type="submit">ใช้โหมดไม่ใช้ AI</button></form>
            </div>
        @else
            <h1 style="font-size:1.8rem">{{ $draft->mode === 'ai' ? 'AI ขอถามก่อนสรุป' : 'เลือกรูปแบบรายงาน' }}</h1>
            @if ($draft->understanding)
                <div class="card" style="margin-bottom:1rem;background:var(--accent-soft);border-color:#f3d9a5">
                    <b style="color:var(--accent-text)">✦ AI เข้าใจว่า:</b> {{ $draft->understanding }}
                </div>
            @endif
            <p class="muted">ตอบเท่าที่สะดวก ข้อที่ไม่ตอบระบบจะเลือกให้</p>

            <form method="POST" action="{{ route('drafts.answer', $draft) }}" style="display:grid;gap:1rem">
                @csrf
                @foreach ($draft->questions as $i => $q)
                    <fieldset class="card" style="margin:0">
                        <legend style="font-weight:700;font-size:1.1rem;padding:0;margin-bottom:.4rem;float:left;width:100%">{{ $i + 1 }}. {{ $q['text'] }}</legend>
                        @if (! empty($q['hint']))<p class="muted" style="margin:0 0 .6rem;clear:both">{{ $q['hint'] }}</p>@endif
                        <div style="display:grid;gap:.5rem;clear:both">
                            @if ($q['type'] === 'single_choice' || $q['type'] === 'multi_choice')
                                @foreach ($q['options'] as $j => $o)
                                    <label class="choice-row">
                                        <input type="{{ $q['type'] === 'single_choice' ? 'radio' : 'checkbox' }}" name="answers[{{ $q['id'] }}]{{ $q['type'] === 'multi_choice' ? '[]' : '' }}" value="{{ $o['value'] }}"
                                            @checked($q['type'] === 'single_choice' ? $j === 0 && $q['id'] === 'audience' : false)>
                                        <span>{{ $o['label'] }}</span>
                                    </label>
                                @endforeach
                            @else
                                <input type="text" name="answers[{{ $q['id'] }}]" maxlength="500" placeholder="พิมพ์คำตอบ (ไม่บังคับ)" style="width:100%">
                            @endif
                        </div>
                        @error('answers.'.$q['id'])<div class="field-error">{{ $message }}</div>@enderror
                    </fieldset>
                @endforeach
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
                    <a class="btn btn-outline" href="{{ route('datasets.show', $dataset) }}">‹ กลับไปตรวจข้อมูล</a>
                    <button class="btn btn-primary btn-lg" type="submit">{{ $draft->mode === 'ai' ? 'ให้ AI สร้างรายงาน' : 'สร้างรายงาน' }} ›</button>
                </div>
            </form>
        @endif
    </div>
</main>
@endsection
