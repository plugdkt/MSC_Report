@extends('layouts.app')

@section('title', 'อยากให้สรุปอะไร')

@section('body')
<main>
    <div class="container" style="max-width:52rem">
        @include('datasets._steps', ['current' => 4])
        <p class="muted" style="margin:0 0 .3rem">📄 {{ $dataset->name }}{{ $dataset->period_label ? ' · งวด '.$dataset->period_label : '' }}</p>
        <h1 style="font-size:1.8rem">อยากให้สรุปอะไร?</h1>
        <p class="muted">เล่าคร่าว ๆ ได้เลย เช่น สิ่งที่อยากรู้ ประเด็นที่ต้องเน้น หรือใครจะเป็นผู้อ่าน — เว้นว่างไว้ก็ได้ AI จะเลือกมุมมองที่เหมาะกับข้อมูลให้</p>

        @if (session('error'))<div class="alert alert-error" role="alert">{{ session('error') }}</div>@endif

        <form class="card" method="POST" action="{{ route('drafts.start', $dataset) }}" style="display:grid;gap:1rem" data-busy="กำลังส่งให้ AI…">
            @csrf
            <input type="hidden" name="mode" value="ai">
            <label style="display:grid;gap:.4rem;font-weight:600">คำอธิบาย (ไม่บังคับ)
                <textarea id="brief" name="brief" rows="5" maxlength="1500" placeholder="เช่น อยากให้เห็นภาพรวมของงวดนี้ เทียบแต่ละหน่วยงาน และชี้ว่าที่ไหนต้องติดตามเป็นพิเศษ สำหรับนำเสนอรองคณบดี" style="width:100%;font-weight:400">{{ old('brief') }}</textarea>
            </label>
            @if ($examples)
                <div>
                    <div class="muted" style="margin-bottom:.4rem">ลองกดเลือกเป็นจุดเริ่มต้น:</div>
                    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                        @foreach ($examples as $ex)
                            <button type="button" class="btn btn-ghost" style="min-height:2.2rem;padding:.2rem .9rem;font-size:.95rem" data-example="{{ $ex }}">{{ $ex }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($insights)
                <div>
                    <div class="muted" style="margin-bottom:.4rem">ข้อสังเกตที่ระบบพบในข้อมูล — กดเพื่อใส่ลงในคำอธิบายว่าอยากให้แสดง:</div>
                    <div style="display:grid;gap:.4rem">
                        @foreach ($insights as $ins)
                            <button type="button" class="btn btn-ghost" style="justify-content:flex-start;text-align:left;min-height:2.2rem;padding:.3rem .9rem;font-size:.95rem;white-space:normal" data-insight="{{ $ins['text'] }}">＋ {{ $ins['text'] }}</button>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($needConsent)
                <div style="background:var(--accent-soft);border:1px solid #f3d9a5;border-radius:10px;padding:.9rem 1rem">
                    <b>ก่อนใช้ AI ครั้งแรก — สิ่งที่ระบบส่งให้ AI ภายในองค์กร</b>
                    <ul style="margin:.3rem 0 .6rem;padding-left:1.3rem;line-height:1.7">
                        <li>ส่งเฉพาะ "สรุปโครงสร้างข้อมูล": ชื่อคอลัมน์ ชนิด ผลรวม/ต่ำสุด-สูงสุด ค่าที่พบบ่อย (ไม่ส่งไฟล์ทั้งไฟล์ ไม่ส่งทุกแถว)</li>
                        <li>ข้อมูลส่วนบุคคล (ชื่อ เบอร์โทร เลขบัตร อีเมล) ถูกปิดบังก่อนส่ง</li>
                        <li>AI ไม่คำนวณตัวเลข — ตัวเลขทุกตัวคำนวณโดยระบบจากข้อมูลจริง และหักโควต้า token ของคุณเอง</li>
                    </ul>
                    <label style="display:flex;gap:.6rem;align-items:flex-start;font-weight:600">
                        <input type="checkbox" name="consent" value="1" required style="margin-top:.35rem"><span>รับทราบและยินยอมให้ส่งข้อมูลสรุปตามนี้</span>
                    </label>
                </div>
            @endif

            <label style="display:flex;gap:.6rem;align-items:flex-start">
                <input type="hidden" name="send_samples" value="0">
                <input type="checkbox" name="send_samples" value="1" checked style="margin-top:.35rem">
                <span>ส่งตัวอย่างข้อมูลไม่กี่แถวให้ AI ด้วย (ช่วยให้เข้าใจข้อมูลแม่นขึ้น)<br><span class="muted">ไม่เลือก = ส่งเฉพาะชื่อคอลัมน์และสถิติ</span></span>
            </label>

            <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
                <a class="btn btn-outline" href="{{ route('datasets.show', $dataset) }}">‹ กลับไปตรวจข้อมูล</a>
                <span style="text-align:right">
                    <button class="btn btn-primary btn-lg" type="submit">ให้ AI สร้างรายงาน ›</button>
                    <div class="muted" style="font-size:.85rem;margin-top:.2rem">ใช้ราว {{ number_format($estimate) }} token{{ $source === 'shared' ? ' (key กลาง)' : '' }}</div>
                </span>
            </div>
        </form>
    </div>
</main>
<script>
    document.querySelectorAll('[data-insight]').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = document.getElementById('brief');
            t.value = (t.value.trim() ? t.value.trim() + '\n' : '') + '- อยากเห็น: ' + b.dataset.insight;
            b.disabled = true;
            t.focus();
        });
    });
    document.querySelectorAll('[data-example]').forEach(function (b) {
        b.addEventListener('click', function () {
            var t = document.getElementById('brief');
            t.value = b.dataset.example;
            t.focus();
        });
    });
</script>
@endsection
