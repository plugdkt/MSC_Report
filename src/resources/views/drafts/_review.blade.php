{{-- ขั้นตรวจแผน: เห็นว่าแต่ละส่วนตอบอะไร + ตัวเลขตัวอย่างจากข้อมูลจริง ก่อนสร้างรายงาน --}}
@php
    $typeLabels = ['kpi' => 'ตัวเลขเด่น', 'gauge' => 'เกจร้อยละ', 'bar' => 'กราฟแท่ง', 'line' => 'กราฟเส้น', 'pie' => 'กราฟวงกลม', 'table' => 'ตาราง'];
    $preview = function (array $w, ?array $d): string {
        if (! $d) {
            return 'ไม่มีข้อมูล';
        }
        if (in_array($w['type'], ['kpi', 'gauge'], true)) {
            $k = \App\Support\Thai::kpi($d['value'] ?? null, $d['unit'] ?? null);

            return trim($k['value'].' '.$k['unit']);
        }
        if ($w['type'] === 'table') {
            $rows = array_values(array_filter($d['rows'] ?? [], fn ($r) => ($r[0] ?? null) !== 'รวม'));
            $names = array_map(fn ($r) => mb_strimwidth((string) $r[0], 0, 34, '…'), array_slice($rows, 0, 3));

            return count($rows).' แถว'.($names ? ' เช่น '.implode(', ', $names) : '');
        }
        $pairs = collect($d['categories'] ?? [])->zip($d['series'][0]['data'] ?? [])->filter(fn ($p) => $p[1] !== null);
        if ($pairs->isEmpty()) {
            return 'ไม่มีข้อมูล';
        }
        $unit = $d['series'][0]['unit'] ?? null;
        $fmt = fn ($v) => trim(implode(' ', array_slice(\App\Support\Thai::kpi($v, $unit), 0, 2)));
        $hi = $pairs->sortByDesc(fn ($p) => $p[1])->first();
        $lo = $pairs->sortBy(fn ($p) => $p[1])->first();

        return $pairs->count().' กลุ่ม · สูงสุด '.mb_strimwidth((string) $hi[0], 0, 30, '…').' '.$fmt($hi[1]).($pairs->count() > 1 ? ' · ต่ำสุด '.mb_strimwidth((string) $lo[0], 0, 30, '…').' '.$fmt($lo[1]) : '');
    };
@endphp

<h1 style="font-size:1.8rem;margin-bottom:.2rem">ตรวจแผนรายงานก่อนสร้าง</h1>
<p class="muted" style="margin-top:0">{{ $review['source'] === 'ai' ? 'AI เสนอส่วนต่าง ๆ ตามที่คุณอธิบาย' : 'แผนจากกฎมาตรฐาน' }} — ตัวเลขด้านล่างคำนวณจากข้อมูลจริงแล้ว ติ๊กออกส่วนที่ไม่ต้องการ หรือเพิ่มจากข้อสังเกตด้านล่าง</p>
@if ($draft->brief)
    <div class="card" style="margin-bottom:1rem;background:var(--accent-soft);border-color:#f3d9a5"><b style="color:var(--accent-text)">สิ่งที่คุณอยากรู้:</b> {{ $draft->brief }}</div>
@endif
@if ($draft->error_message)<div class="alert alert-error" role="alert">{{ $draft->error_message }}</div>@endif
@if (session('error'))<div class="alert alert-error" role="alert">{{ session('error') }}</div>@endif

<form method="POST" action="{{ route('drafts.approve', $draft) }}" style="display:grid;gap:1rem">
    @csrf
    <section class="card" style="margin:0">
        <h2 style="font-size:1.3rem;margin:0">{{ $review['spec']['title'] }}</h2>
        @if (! empty($review['spec']['subtitle']))<p class="muted" style="margin:.2rem 0 0">{{ $review['spec']['subtitle'] }}</p>@endif
        <div style="display:grid;gap:.2rem;margin-top:.8rem">
            @foreach ($review['spec']['widgets'] as $i => $w)
                <label class="choice-row" style="align-items:flex-start;padding:.7rem .4rem;border-top:1px solid var(--border)">
                    <input type="checkbox" name="keep[]" value="{{ $w['id'] }}" checked style="margin-top:.35rem">
                    <span style="display:grid;gap:.15rem">
                        <span><b>{{ $i + 1 }}. {{ $w['title'] }}</b> <span class="badge">{{ $typeLabels[$w['type']] ?? $w['type'] }}</span></span>
                        @if (! empty($w['purpose']))<span class="muted">ตอบ: {{ $w['purpose'] }}</span>@endif
                        <span style="color:var(--primary,#1f3b6e);font-weight:600">{{ $preview($w, $review['computed'][$w['id']] ?? null) }}</span>
                    </span>
                </label>
            @endforeach
        </div>
    </section>

    @if ($review['extra']->isNotEmpty())
        <section class="card" style="margin:0">
            <h2 style="font-size:1.15rem;margin:0 0 .2rem">ข้อสังเกตอื่นจากข้อมูล</h2>
            <p class="muted" style="margin:0 0 .6rem">ระบบคำนวณจากข้อมูลจริง — ติ๊กเพื่อเพิ่มเป็นส่วนหนึ่งของรายงาน</p>
            <div style="display:grid;gap:.3rem">
                @foreach ($review['extra'] as $ins)
                    <label class="choice-row" style="align-items:flex-start"><input type="checkbox" name="add[]" value="{{ $ins['id'] }}" style="margin-top:.35rem"><span>{{ $ins['text'] }}</span></label>
                @endforeach
            </div>
        </section>
    @endif

    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap">
        <a class="btn btn-outline" href="{{ route('datasets.show', $dataset) }}">‹ กลับไปตรวจข้อมูล</a>
        <button class="btn btn-primary btn-lg" type="submit">สร้างรายงานตามนี้ ›</button>
    </div>
</form>

@if ($draft->mode === 'ai')
    <form method="POST" action="{{ route('drafts.revise', $draft) }}" class="card" style="margin-top:1rem;display:grid;gap:.6rem" data-busy="AI กำลังปรับแผน…">
        @csrf
        <h2 style="font-size:1.15rem;margin:0">ยังไม่ตรงใจ? บอก AI ให้ปรับแผน</h2>
        <p class="muted" style="margin:0">เช่น "เน้นรายโครงการที่ยังไม่เบิกจ่าย" หรือ "ไม่ต้องแยกตามประเภทเงิน ให้เทียบตามแผนงานแทน"</p>
        <textarea name="instruction" rows="2" maxlength="800" required style="width:100%">{{ old('instruction') }}</textarea>
        @error('instruction')<div class="field-error">{{ $message }}</div>@enderror
        <div><button class="btn btn-outline" type="submit">ปรับแผน</button></div>
    </form>
@endif
