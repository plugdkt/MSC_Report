<?php

namespace App\Services\Import;

/**
 * หาแถวหัวตาราง (รวมหัวหลายแถว), ข้อความหัวรายงาน, และจำแนกแถวข้อมูล/แถวผลรวม/ส่วนท้าย
 * (spec FR-1.5, FR-1.5.1, FR-1.8, FR-1.9)
 */
class SheetAnalyzer
{
    private const HEADER_SCAN_ROWS = 20;

    private const TOTAL_LABEL = '/^(ยอด\s*)?รวม(\s*ทั้งสิ้น|\s*ทั้งหมด|\s*สุทธิ)?$|^รวม\s|^ยอดรวม|^(grand\s+)?total\b|^sub\s*-?total\b/iu';

    private const GRAND_LABEL = '/ทั้งสิ้น|ทั้งหมด|สุทธิ|grand/iu';

    private const FOOTER_LABEL = '/^(ผู้รายงาน|ผู้ทาน|ผู้ตรวจ|ผู้อนุมัติ|ลงชื่อ|หมายเหตุ|\(ลงชื่อ\))/u';

    private const SYMBOL_ONLY = '/^[\s\(\)\+\-\=,\/]+$/u';

    public function analyze(SheetGrid $grid, ?int $headerStart = null, ?int $headerEnd = null): SheetAnalysis
    {
        $headerStart ??= $this->detectHeaderRow($grid);
        $headerEnd ??= $this->detectHeaderEnd($grid, $headerStart);
        $headerEnd = max($headerStart, $headerEnd);

        $rowKinds = $this->classifyRows($grid, $headerStart, $headerEnd, $this->firstHeaderColumn($grid, $headerStart));
        $columns = $this->columnNames($grid, $headerStart, $headerEnd, $rowKinds);
        $titleLines = $this->titleLines($grid, $headerStart);

        $analysis = new SheetAnalysis($grid->name, $headerStart, $headerEnd, $columns, $titleLines, $rowKinds, 0);

        return new SheetAnalysis($grid->name, $headerStart, $headerEnd, $columns, $titleLines, $rowKinds, $this->score($analysis));
    }

    public function detectHeaderRow(SheetGrid $grid): int
    {
        $best = 1;
        $bestScore = -1.0;
        $last = min($grid->highestRow, self::HEADER_SCAN_ROWS);

        for ($r = 1; $r <= $last; $r++) {
            $filled = $grid->filled($r);
            $n = count($filled);
            if ($n < 2) {
                continue;
            }
            $texts = count(array_filter($filled, fn (Cell $c) => ! $c->isNumber() && ! $c->isDate && ! preg_match(self::SYMBOL_ONLY, $c->text())));
            if ($texts < 2 || $texts / $n < 0.6) {
                continue;
            }
            $dataLike = 0;
            for ($k = $r + 1; $k <= min($grid->highestRow, $r + 6); $k++) {
                foreach ($grid->filled($k) as $c) {
                    if ($c->isNumber() || $c->isDate) {
                        $dataLike++;
                        break;
                    }
                }
            }
            $score = $texts + $dataLike * 1.5;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $r;
            }
        }

        return $best;
    }

    /**
     * หัวตารางต่อเนื่อง: แถวถัดไปที่ไม่มีตัวเลข/วันที่ และคอลัมน์แรกของหัวตารางว่าง (เช่น แถวคำต่อท้าย, แถว "(+) (-) (=)")
     */
    public function detectHeaderEnd(SheetGrid $grid, int $headerStart): int
    {
        $firstCol = $this->firstHeaderColumn($grid, $headerStart);
        $headerCount = count($grid->filled($headerStart));
        $end = $headerStart;

        for ($r = $headerStart + 1; $r <= min($grid->highestRow, $headerStart + 3); $r++) {
            $filled = $grid->filled($r);
            if ($filled === [] || count($filled) > $headerCount) {
                break;
            }
            foreach ($filled as $c) {
                if ($c->isNumber() || $c->isDate) {
                    break 2;
                }
            }
            if (! $grid->cell($r, $firstCol)->isEmpty()) {
                break;
            }
            $end = $r;
        }

        return $end;
    }

    private function firstHeaderColumn(SheetGrid $grid, int $headerStart): int
    {
        $cols = array_keys($grid->filled($headerStart));

        return $cols === [] ? 1 : min($cols);
    }

    /**
     * @return array<int, string>
     */
    private function classifyRows(SheetGrid $grid, int $headerStart, int $headerEnd, int $keyCol): array
    {
        $headerTexts = array_map(fn (Cell $c) => $c->text(), $grid->filled($headerStart));
        $inRepeat = false;
        $kinds = [];
        $blankRun = 0;
        $seenData = false;
        $seenGrand = false;
        $footer = false;

        for ($r = $headerEnd + 1; $r <= $grid->highestRow; $r++) {
            if ($footer) {
                $kinds[$r] = SheetAnalysis::KIND_FOOTER;

                continue;
            }
            if ($grid->isBlankRow($r)) {
                $kinds[$r] = SheetAnalysis::KIND_BLANK;
                if ($seenData && ++$blankRun >= 2) {
                    $footer = true;
                }

                continue;
            }
            $blankRun = 0;
            $filled = $grid->filled($r);

            $labels = $this->totalLabels($filled);
            $hasNumber = (bool) array_filter($filled, fn (Cell $c) => $c->isNumber() || $c->isDate);
            if ($this->isRepeatedHeader($filled, $headerTexts)) {
                $kinds[$r] = SheetAnalysis::KIND_REPEAT;
                $inRepeat = true;

                continue;
            }
            if ($inRepeat && ! $hasNumber && $grid->cell($r, $keyCol)->isEmpty()) {
                $kinds[$r] = SheetAnalysis::KIND_REPEAT;

                continue;
            }
            $inRepeat = false;

            if ($this->isFooterRow($filled)) {
                $footer = true;
                $kinds[$r] = SheetAnalysis::KIND_FOOTER;
            } elseif ($labels !== []) {
                $kinds[$r] = preg_grep(self::GRAND_LABEL, $labels) ? SheetAnalysis::KIND_TOTAL : SheetAnalysis::KIND_SUBTOTAL;
                $seenGrand = $seenGrand || $kinds[$r] === SheetAnalysis::KIND_TOTAL;
            } elseif ($seenGrand) {
                $kinds[$r] = SheetAnalysis::KIND_CHECK;
            } elseif ($grid->cell($r, $keyCol)->isEmpty() && $this->hasAggregateFormula($filled)) {
                $kinds[$r] = SheetAnalysis::KIND_SUBTOTAL;
            } else {
                $kinds[$r] = SheetAnalysis::KIND_DATA;
                $seenData = true;
            }
        }

        return $this->promoteFinalTotal($kinds);
    }

    /**
     * แถว "รวม" สุดท้ายที่อยู่หลังข้อมูลทั้งหมด = ยอดรวมทั้งสิ้น
     *
     * @param  array<int, string>  $kinds
     * @return array<int, string>
     */
    private function promoteFinalTotal(array $kinds): array
    {
        if (in_array(SheetAnalysis::KIND_TOTAL, $kinds, true)) {
            return $kinds;
        }
        $lastData = max(array_keys($kinds, SheetAnalysis::KIND_DATA, true) ?: [0]);
        foreach (array_reverse($kinds, true) as $row => $kind) {
            if ($kind === SheetAnalysis::KIND_SUBTOTAL && $row > $lastData) {
                $kinds[$row] = SheetAnalysis::KIND_TOTAL;
                break;
            }
        }

        return $kinds;
    }

    /**
     * @param  array<int, Cell>  $filled
     * @return list<string>
     */
    private function totalLabels(array $filled): array
    {
        $labels = [];
        foreach ($filled as $c) {
            if (! $c->isNumber() && ! $c->isDate && preg_match(self::TOTAL_LABEL, $c->text())) {
                $labels[] = $c->text();
            }
        }

        return $labels;
    }

    /**
     * แถวที่ข้อความตรงกับหัวตาราง (หัวตารางพิมพ์ซ้ำเมื่อขึ้นหน้าใหม่)
     *
     * @param  array<int, Cell>  $filled
     * @param  array<int, string>  $headerTexts
     */
    private function isRepeatedHeader(array $filled, array $headerTexts): bool
    {
        if (count($headerTexts) < 2 || count($filled) < 2) {
            return false;
        }
        $same = 0;
        foreach ($filled as $col => $c) {
            if (($headerTexts[$col] ?? null) === $c->text()) {
                $same++;
            }
        }

        return $same / count($headerTexts) >= 0.7;
    }

    /**
     * @param  array<int, Cell>  $filled
     */
    private function isFooterRow(array $filled): bool
    {
        $first = reset($filled);

        return $first !== false && ! $first->isNumber() && preg_match(self::FOOTER_LABEL, $first->text()) === 1;
    }

    /**
     * @param  array<int, Cell>  $filled
     */
    private function hasAggregateFormula(array $filled): bool
    {
        foreach ($filled as $c) {
            if ($c->hasAggregateFormula()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $rowKinds
     * @return array<int, string>
     */
    private function columnNames(SheetGrid $grid, int $headerStart, int $headerEnd, array $rowKinds): array
    {
        $cols = [];
        for ($r = $headerStart; $r <= $headerEnd; $r++) {
            foreach (array_keys($grid->filled($r)) as $c) {
                $cols[$c] = true;
            }
        }
        foreach ($rowKinds as $r => $kind) {
            if ($kind === SheetAnalysis::KIND_DATA) {
                foreach (array_keys($grid->filled($r)) as $c) {
                    $cols[$c] = true;
                }
            }
        }
        ksort($cols);

        $names = [];
        foreach (array_keys($cols) as $c) {
            $parts = [];
            for ($r = $headerStart; $r <= $headerEnd; $r++) {
                $t = $grid->mergedText($r, $c);
                if ($t !== '' && ! preg_match(self::SYMBOL_ONLY, $t) && ! in_array($t, $parts, true)) {
                    $parts[] = $t;
                }
            }
            $names[$c] = trim(preg_replace('/\s+/u', ' ', implode(' ', $parts)) ?? '');
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function titleLines(SheetGrid $grid, int $headerStart): array
    {
        $lines = [];
        for ($r = 1; $r < $headerStart; $r++) {
            $texts = array_map(fn (Cell $c) => $c->text(), $grid->filled($r));
            if ($texts !== []) {
                $lines[] = trim(preg_replace('/\s+/u', ' ', implode(' ', $texts)) ?? '');
            }
        }

        return $lines;
    }

    /**
     * คะแนนความเป็น "ตารางข้อมูลดิบ" — ใช้แนะนำชีตที่ควรเลือก (FR-1.9)
     */
    private function score(SheetAnalysis $a): float
    {
        $data = count($a->dataRows());
        if ($data < 3) {
            return -10;
        }
        $subtotals = $a->countKind(SheetAnalysis::KIND_SUBTOTAL);

        $score = 0.0;
        $score += $a->headerStart <= 2 ? 3 : 0;
        $score += $a->headerEnd === $a->headerStart ? 2 : 0;
        $score += $subtotals === 0 ? 3 : -min(3, $subtotals / 5);
        $score += min(4, log10($data + 1) * 2);
        $score += min(2, count($a->columns) * 0.1);

        return round($score, 2);
    }
}
