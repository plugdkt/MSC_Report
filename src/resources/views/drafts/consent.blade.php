@extends('layouts.app')

@section('title', 'ก่อนใช้ AI')

@section('body')
<main>
    <div class="container" style="max-width:46rem">
        <div class="page-head"><div><h1>ก่อนให้ AI ช่วยสรุป</h1><p class="muted" style="margin:.2rem 0 0">รับทราบสิ่งที่ระบบจะส่งให้ AI ภายในองค์กร</p></div></div>
        <form class="card" method="POST" action="{{ route('drafts.start', $dataset) }}" style="display:grid;gap:1rem">
            @csrf
            <input type="hidden" name="mode" value="ai">
            <input type="hidden" name="consent" value="1">
            <div>
                <p style="margin-top:0"><b>ระบบส่งให้ AI เฉพาะ "สรุปโครงสร้างข้อมูล"</b> ได้แก่ ชื่อคอลัมน์ ชนิดข้อมูล ผลรวม/ค่าต่ำสุด-สูงสุด ค่าที่พบบ่อย และตัวอย่างไม่กี่แถว</p>
                <ul style="margin:.3rem 0 0;padding-left:1.3rem;line-height:1.7">
                    <li>ไม่ส่งไฟล์ Excel ทั้งไฟล์ และไม่ส่งข้อมูลทุกแถว</li>
                    <li>คอลัมน์ที่ระบบเห็นว่าเป็นข้อมูลส่วนบุคคล (ชื่อ เบอร์โทร เลขบัตร อีเมล) จะถูกปิดบังก่อนส่ง</li>
                    <li>AI ไม่คำนวณตัวเลข — ตัวเลขทุกตัวในรายงานคำนวณโดยระบบจากข้อมูลจริง</li>
                    <li>ใช้ AI ภายในองค์กร (ไม่ส่งออกนอกองค์กร) และหักโควต้า token ของคุณเอง</li>
                </ul>
            </div>
            <label style="display:flex;gap:.6rem;align-items:flex-start;font-weight:600">
                <input type="hidden" name="send_samples" value="0">
                <input type="checkbox" name="send_samples" value="1" checked style="margin-top:.35rem">
                <span>ส่งตัวอย่างข้อมูลไม่กี่แถวให้ AI ด้วย (ช่วยให้ AI เข้าใจข้อมูลแม่นขึ้น)<br><span class="muted" style="font-weight:400">ไม่เลือก = ส่งเฉพาะชื่อคอลัมน์และสถิติ</span></span>
            </label>
            <div style="display:flex;gap:.6rem;justify-content:flex-end;flex-wrap:wrap">
                <a class="btn btn-ghost" href="{{ route('datasets.show', $dataset) }}">ยกเลิก</a>
                <button class="btn btn-primary btn-lg" type="submit">รับทราบ และให้ AI ช่วยสรุป ›</button>
            </div>
        </form>
    </div>
</main>
@endsection
