@extends('layouts.app')

@section('title', 'จัดการผู้ใช้')

@section('body')
<main>
    <div class="container">
        <div class="page-head">
            <div>
                <h1>จัดการผู้ใช้</h1>
                <p class="muted" style="margin:.2rem 0 0">ผู้ใช้ถูกสร้างอัตโนมัติเมื่อเข้าสู่ระบบด้วย UP Account ครั้งแรก (บทบาทเริ่มต้น: เจ้าหน้าที่)</p>
            </div>
        </div>

        @if (session('status'))
            <div class="alert alert-ok" role="status">{{ session('status') }}</div>
        @endif
        @if (session('error'))
            <div class="alert alert-error" role="alert">{{ session('error') }}</div>
        @endif

        <div class="card">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th>ชื่อ-สกุล</th><th>ตำแหน่ง / หน่วยงาน</th><th>เข้าใช้ล่าสุด</th><th>สิทธิ์</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($users as $u)
                            <tr>
                                <td>
                                    <strong>{{ $u->name }}</strong><br>
                                    <span class="muted" style="font-size:.9rem">{{ $u->username }}</span>
                                    @unless ($u->is_active) <span class="badge badge-off">ระงับ</span> @endunless
                                </td>
                                <td>{{ $u->pos_name ?? '—' }}<br><span class="muted" style="font-size:.9rem">{{ $u->div_name ?? '' }}</span></td>
                                <td style="white-space:nowrap">{{ $u->last_login_at?->locale('th')->translatedFormat('j M') }} {{ $u->last_login_at ? $u->last_login_at->year + 543 : '—' }}<br><span class="muted" style="font-size:.9rem">{{ $u->last_login_at?->format('H:i') }}</span></td>
                                <td>
                                    <form class="inline-form" method="POST" action="{{ route('admin.users.update', $u) }}">
                                        @csrf @method('PUT')
                                        <label class="sr-only" for="role-{{ $u->id }}">บทบาท</label>
                                        <select id="role-{{ $u->id }}" name="role">
                                            @foreach ($roles as $key => $label)
                                                <option value="{{ $key }}" @selected($u->role === $key)>{{ $label }}</option>
                                            @endforeach
                                        </select>
                                        <input type="hidden" name="is_active" value="0">
                                        <label class="check"><input type="checkbox" name="is_active" value="1" @checked($u->is_active)> ใช้งาน</label>
                                        <button class="btn btn-outline" type="submit">บันทึก</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $users->links() }}
        </div>
    </div>
</main>
@endsection
