@extends('layouts.app')

@section('title', 'สร้างรายงานใหม่')

@section('body')
<main>
    <div class="container" style="max-width:52rem">
        @include('datasets._steps', ['current' => 1])
        <h1 style="font-size:1.8rem">อัปโหลดไฟล์ Excel</h1>
        <p class="muted">ใช้ไฟล์ที่คุณมีอยู่ได้เลย ระบบจะหาตารางข้อมูล ตัดแถวยอดรวม และแปลงวันที่ พ.ศ. ให้อัตโนมัติ</p>

        <form class="card" method="POST" action="{{ route('datasets.store') }}" enctype="multipart/form-data" id="upload-form">
            @csrf
            <label class="dropzone" id="dropzone">
                <input type="file" name="file" id="file" accept=".xlsx,.xls,.csv" required>
                <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V4M6 10l6-6 6 6"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/></svg>
                <span class="big">ลากไฟล์มาวางที่นี่ หรือกดเพื่อเลือกไฟล์</span>
                <span class="muted">รองรับ .xlsx, .xls, .csv · ขนาดไม่เกิน {{ $maxMb }} MB</span>
                <span class="file-name" id="file-name"></span>
            </label>
            @error('file')
                <div class="field-error" role="alert">{{ $message }}</div>
            @enderror
            <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;margin-top:1.2rem">
                <span class="muted" style="font-size:.9rem">🔒 ไฟล์ถูกเก็บในเซิร์ฟเวอร์ของคณะ และลบอัตโนมัติภายใน {{ config('import.retention_days') }} วัน</span>
                <button class="btn btn-primary btn-lg" type="submit" id="submit-btn">อัปโหลดและตรวจไฟล์</button>
            </div>
        </form>
    </div>
</main>
@endsection

@push('scripts')
<script>
(function () {
    var dz = document.getElementById('dropzone'), input = document.getElementById('file'), nameEl = document.getElementById('file-name');
    function show() { nameEl.textContent = input.files.length ? '📄 ' + input.files[0].name : ''; }
    input.addEventListener('change', show);
    ['dragenter', 'dragover'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.add('drag'); }); });
    ['dragleave', 'drop'].forEach(function (ev) { dz.addEventListener(ev, function (e) { e.preventDefault(); dz.classList.remove('drag'); }); });
    dz.addEventListener('drop', function (e) { if (e.dataTransfer.files.length) { input.files = e.dataTransfer.files; show(); } });
    document.getElementById('upload-form').addEventListener('submit', function () {
        var b = document.getElementById('submit-btn'); b.disabled = true; b.textContent = 'กำลังตรวจไฟล์…';
    });
})();
</script>
@endpush
