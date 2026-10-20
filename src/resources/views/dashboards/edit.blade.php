@extends('layouts.app')

@section('title', 'แก้ไข: '.$version->spec['title'])

@push('head')
<link rel="stylesheet" href="{{ asset('vendor/gridstack.min.css') }}">
<script src="{{ asset('vendor/gridstack-all.min.js') }}"></script>
<script src="{{ asset('vendor/echarts.min.js') }}"></script>
@endpush

@section('body')
<main>
    <div class="container">
        <div class="editor-bar card">
            <div class="editor-fields">
                <label>ชื่อรายงาน<input type="text" id="f-title" maxlength="200"></label>
                <label>คำอธิบายใต้ชื่อ<input type="text" id="f-subtitle" maxlength="300"></label>
                <label>ธีม<select id="f-theme">@foreach ($themes as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
            </div>
            <div class="editor-actions">
                <button class="btn btn-ghost" type="button" id="btn-add">+ เพิ่มส่วน</button>
                <a class="btn btn-ghost" href="{{ route('dashboards.show', $dashboard) }}">ยกเลิก</a>
                <button class="btn btn-primary" type="button" id="btn-save">บันทึก</button>
            </div>
            <div id="msg" class="editor-msg" role="status" aria-live="polite"></div>
        </div>
        <p class="muted" style="margin:.6rem 0">ลากที่หัวการ์ดเพื่อย้าย · ลากมุมขวาล่างเพื่อปรับขนาด · กด ✎ เพื่อแก้ข้อมูลในส่วนนั้น</p>
        <div class="grid-stack dash-edit" id="grid"></div>
    </div>

    <dialog id="panel" class="w-panel">
        <form method="dialog" id="panel-form">
            <h2 style="font-size:1.25rem;margin:0 0 .8rem">แก้ไขส่วนนี้</h2>
            <div class="panel-grid">
                <label class="span2">หัวข้อ<input type="text" name="title" maxlength="150" required></label>
                <label>รูปแบบ
                    <select name="type">
                        <option value="kpi">ตัวเลขสรุป (KPI)</option><option value="bar">กราฟแท่ง</option><option value="line">กราฟเส้น</option>
                        <option value="pie">กราฟวงกลม</option><option value="gauge">มาตรวัดร้อยละ</option><option value="table">ตาราง</option>
                    </select>
                </label>
                <label data-for="target">เป้าหมาย (%)<input type="text" inputmode="decimal" name="target"></label>
            </div>
            <fieldset class="panel-box"><legend>ตัวเลขที่แสดง</legend><div id="measures"></div>
                <button type="button" class="btn btn-ghost" id="btn-add-measure" style="min-height:2.2rem;margin-top:.4rem">+ เพิ่มตัวเลข</button></fieldset>
            <fieldset class="panel-box" data-for="dimension"><legend>แยกตาม</legend>
                <div class="panel-grid">
                    <label>คอลัมน์<select name="dim"></select></label>
                    <label data-for="grain">ช่วงเวลา<select name="grain"><option value="month">รายเดือน</option><option value="fiscal_quarter">ไตรมาส (ปีงบ)</option><option value="fiscal_year">ปีงบประมาณ</option><option value="quarter">ไตรมาส (ปีปฏิทิน)</option><option value="year">ปี</option><option value="day">รายวัน</option></select></label>
                    <label data-for="groupby">แสดงเป็น<select name="groupby"><option value="label">ค่าแต่ละรายการ</option><option value="group">จัดกลุ่ม (เช่น งบรายจ่าย)</option></select></label>
                    <label>เรียง<select name="sort"><option value="desc">มากไปน้อย</option><option value="asc">น้อยไปมาก</option><option value="none">ตามข้อมูล</option></select></label>
                    <label>แสดงสูงสุด (รายการ)<input type="text" inputmode="numeric" name="limit" placeholder="อัตโนมัติ"></label>
                </div>
            </fieldset>
            <div id="panel-err" class="field-error"></div>
            <div style="display:flex;justify-content:space-between;gap:.6rem;margin-top:1rem">
                <button type="button" class="btn btn-ghost" id="btn-delete">ลบส่วนนี้</button>
                <span style="display:flex;gap:.6rem"><button type="button" class="btn btn-ghost" id="btn-cancel">ยกเลิก</button><button type="submit" class="btn btn-primary" value="ok">ใช้การแก้ไข</button></span>
            </div>
        </form>
    </dialog>
</main>
@endsection

@push('scripts')
<script src="{{ asset('js/dashboard-render.js') }}"></script>
<script>
window.MSC_EDITOR = {
    spec: @json($version->spec),
    computed: @json($version->computed_data),
    columns: @json($columns),
    urls: { preview: @json(route('dashboards.preview', $dashboard)), save: @json(route('dashboards.spec', $dashboard)), show: @json(route('dashboards.show', $dashboard)) },
    csrf: @json(csrf_token())
};
</script>
<script src="{{ asset('js/dashboard-editor.js') }}"></script>
@endpush
