@extends('layouts.app')

@section('title', 'ตั้งค่าระบบ')

@section('body')
<main>
    <div class="container" style="max-width:44rem">
        <div class="page-head"><div><h1>ตั้งค่าระบบ</h1><p class="muted" style="margin:.2rem 0 0">ค่าที่ใช้ทั้งองค์กร</p></div></div>
        @include('admin.codes._flash')
        <form class="card" method="POST" action="{{ route('admin.settings.update') }}" style="display:grid;gap:1rem">
            @csrf @method('PUT')
            <label class="muted" style="display:grid;gap:.2rem;font-weight:600">ชื่อหน่วยงาน (แสดงท้ายหน้าเว็บ)
                <input type="text" name="org_name" value="{{ old('org_name', $orgName) }}" maxlength="150" required>
            </label>
            <label class="muted" style="display:grid;gap:.2rem;font-weight:600">ธีมเริ่มต้นของรายงานใหม่
                <select name="default_theme">@foreach ($themes as $k => $l)<option value="{{ $k }}" @selected($theme === $k)>{{ $l }}</option>@endforeach</select>
            </label>
            <div><button class="btn btn-primary" type="submit">บันทึก</button></div>
        </form>
        <p class="muted" style="margin-top:1rem">ตั้งค่า AI ดูที่เมนู "ตั้งค่า AI" · ระยะเก็บไฟล์ส่งออกตั้งใน <code>.env</code> (EXPORT_RETENTION_DAYS)</p>
    </div>
</main>
@endsection
