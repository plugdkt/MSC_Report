@php
    $labels = [1 => 'อัปโหลดไฟล์', 2 => 'เลือกชีต', 3 => 'ตรวจข้อมูล', 4 => 'ตอบคำถาม AI', 5 => 'รายงาน'];
@endphp
<ol class="steps" aria-label="ขั้นตอน">
    @foreach ($labels as $n => $label)
        <li @class(['done' => $n < $current, 'now' => $n === $current]) @if ($n === $current) aria-current="step" @endif>
            <span class="n">{{ $n < $current ? '✓' : $n }}</span>{{ $label }}
        </li>
    @endforeach
</ol>
