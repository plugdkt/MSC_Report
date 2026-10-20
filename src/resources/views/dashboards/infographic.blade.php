@extends('layouts.app')

@section('title', 'อินโฟกราฟิก: '.$spec['title'])

@push('head')
<script src="{{ asset('vendor/echarts.min.js') }}"></script>
@endpush

@php($g = $spec['infographic'] ?? null)

@section('body')
<main>
    <div class="container" style="max-width:1600px">
        <div class="page-head">
            <div>
                <p style="margin:0"><a href="{{ route('dashboards.show', $dashboard) }}">‹ กลับไปที่รายงาน</a></p>
                <h1 style="font-size:1.6rem;margin:.2rem 0 0">อินโฟกราฟิก 1 หน้า</h1>
            </div>
            @if ($data && $canEdit)
                <form method="POST" action="{{ route('dashboards.exports.store', $dashboard) }}" class="toolbar-actions">
                    @csrf
                    <input type="hidden" name="target" value="infographic">
                    <label class="muted" style="display:flex;align-items:center;gap:.4rem">ความละเอียด
                        <select name="scale"><option value="1">ปกติ (1536×1024)</option><option value="2" selected>ชัดมาก 2× (3072×2048)</option><option value="3">สูงสุด 3×</option></select>
                    </label>
                    <button class="btn btn-ghost" type="submit" name="format" value="png">ดาวน์โหลด PNG</button>
                    <button class="btn btn-primary" type="submit" name="format" value="jpeg">ดาวน์โหลด JPEG</button>
                </form>
            @endif
        </div>
        @if (session('status'))<div class="alert alert-ok" role="status">{{ session('status') }}</div>@endif
        @if (session('error'))<div class="alert alert-error" role="alert">{{ session('error') }}</div>@endif
        @if ($errors->any())<div class="alert alert-error" role="alert">{{ $errors->first() }}</div>@endif

        @if (! $data)
            <div class="card empty">
                <h2 style="font-size:1.3rem">ยังไม่ได้ตั้งค่าอินโฟกราฟิก</h2>
                <p class="muted">เลือกตัวเลข 2–4 ส่วนที่รวมกันเป็นยอดทั้งหมด (เช่น ใช้จริง + ผูกพัน + คงเหลือ = งบทั้งหมด) ในแบบฟอร์มด้านล่าง</p>
            </div>
        @else
            <div class="ig-fit" id="fit">
                <div class="ig-scaler" id="scaler">
                    @include('dashboards._infographic_canvas')
                </div>
            </div>
        @endif

        @if ($canEdit)
            <details class="box" style="margin-top:1.2rem" @if (! $data) open @endif>
                <summary>ตั้งค่าอินโฟกราฟิก</summary>
                <form method="POST" action="{{ route('dashboards.infographic.update', $dashboard) }}" style="display:grid;gap:.8rem;margin-top:.8rem">
                    @csrf @method('PUT')
                    <div class="grid-2">
                        <label class="muted" style="display:grid;gap:.2rem;font-weight:600">ชื่อบนแถบหัว<input type="text" name="title" value="{{ $g['title'] ?? '' }}" maxlength="200"></label>
                        <label class="muted" style="display:grid;gap:.2rem;font-weight:600">ป้ายช่วงเวลา<input type="text" name="period" value="{{ $g['period'] ?? '' }}" maxlength="80" placeholder="เช่น เดือน กันยายน 2569"></label>
                        <label class="muted" style="display:grid;gap:.2rem;font-weight:600">หน่วยงาน<input type="text" name="unit" value="{{ $g['unit'] ?? '' }}" maxlength="150"></label>
                        <label class="muted" style="display:grid;gap:.2rem;font-weight:600">ชื่อแถวรวม<input type="text" name="total_label" value="{{ $g['total_label'] ?? 'ทั้งหมด' }}" maxlength="60"></label>
                    </div>
                    <p class="muted" style="margin:0">ส่วนประกอบ (2–4 ส่วน รวมกันเป็นยอดทั้งหมด)</p>
                    @for ($i = 0; $i < 4; $i++)
                        @php($part = $g['parts'][$i] ?? null)
                        <div class="form-row">
                            <label>ชื่อส่วนที่ {{ $i + 1 }}<input type="text" name="parts[{{ $i }}][label]" value="{{ $part['label'] ?? '' }}" maxlength="60" @if ($i < 2) required @endif></label>
                            <label>คอลัมน์<select name="parts[{{ $i }}][column]">
                                @if ($i >= 2)<option value="">— ไม่ใช้ —</option>@endif
                                @foreach ($columns as $c)<option value="{{ $c->slug }}" @selected(($part['columns'][0] ?? null) === $c->slug)>{{ $c->display_name }}</option>@endforeach
                            </select></label>
                            <label>สี<select name="parts[{{ $i }}][color]">
                                @foreach (['green' => 'เขียว', 'red' => 'แดง', 'blue' => 'น้ำเงิน', 'orange' => 'ส้ม', 'navy' => 'กรมท่า', 'gray' => 'เทา'] as $k => $l)
                                    <option value="{{ $k }}" @selected(($part['color'] ?? ['green', 'red', 'blue', 'orange'][$i]) === $k)>{{ $l }}</option>
                                @endforeach
                            </select></label>
                        </div>
                    @endfor
                    <div><button class="btn btn-primary" type="submit">บันทึกอินโฟกราฟิก</button></div>
                </form>
                @if ($data)
                    <form method="POST" action="{{ route('dashboards.image.upload', $dashboard) }}" enctype="multipart/form-data" class="form-row" style="margin-top:1rem;border-top:1px solid var(--border);padding-top:1rem">
                        @csrf
                        <label>รูปหัวรายงาน (อาคาร/โลโก้ · jpg/png ≤ 5 MB)<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label>
                        <button class="btn btn-outline" type="submit">อัปโหลดรูป</button>
                    </form>
                @endif
            </details>
        @endif
    </div>
</main>
@endsection

@push('scripts')
@if ($data)
<script src="{{ asset('js/infographic-render.js') }}"></script>
<script>
(function () {
    var fit = document.getElementById('fit'), scaler = document.getElementById('scaler');
    function rescale() { scaler.style.transform = 'scale(' + (fit.clientWidth / 1536) + ')'; }
    window.addEventListener('resize', rescale); rescale();
    (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () { MscInfographic.draw({ renderer: 'canvas' }); });
})();
</script>
@endif
@endpush
