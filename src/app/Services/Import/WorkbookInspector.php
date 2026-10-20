<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * สำรวจทุกชีตตอนอัปโหลด: โครงสร้าง, ชีตที่แนะนำ และตัวอย่างแถวสำหรับหน้าเลือกชีต
 */
class WorkbookInspector
{
    private const PREVIEW_ROWS_AFTER_HEADER = 18;

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly SheetAnalyzer $analyzer,
    ) {}

    /**
     * @return array{sheets: list<array<string, mixed>>, recommended: ?string}
     */
    public function inspect(string $path): array
    {
        $sheets = [];
        foreach ($this->reader->readAll($path) as $grid) {
            if ($grid->rows === []) {
                continue;
            }
            $sheets[] = $this->describe($grid, $this->analyzer->analyze($grid));
        }

        if ($sheets === []) {
            throw new ImportException('ไม่พบข้อมูลในไฟล์');
        }

        $best = collect($sheets)->filter(fn ($s) => $s['data_rows'] >= 3)->sortByDesc('score')->first();
        foreach ($sheets as &$s) {
            $s['recommended'] = $best !== null && $s['name'] === $best['name'];
        }

        return ['sheets' => $sheets, 'recommended' => $best['name'] ?? null];
    }

    /**
     * ข้อมูลตัวอย่างของชีตตามแถวหัวตารางที่ผู้ใช้เลือกเอง
     *
     * @return array<string, mixed>
     */
    public function inspectSheet(string $path, string $sheet, int $headerStart, int $headerEnd): array
    {
        $grid = $this->reader->readSheet($path, $sheet);

        return $this->describe($grid, $this->analyzer->analyze($grid, $headerStart, $headerEnd));
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(SheetGrid $grid, SheetAnalysis $a): array
    {
        $cols = array_keys($a->columns);
        $from = max(1, $a->headerStart - 3);
        $to = min($grid->highestRow, $a->headerEnd + self::PREVIEW_ROWS_AFTER_HEADER);

        $previewRows = range($from, $to);
        foreach ([SheetAnalysis::KIND_SUBTOTAL, SheetAnalysis::KIND_TOTAL, SheetAnalysis::KIND_CHECK, SheetAnalysis::KIND_REPEAT] as $kind) {
            $previewRows = array_merge($previewRows, array_slice(array_filter($a->rowsOfKind($kind), fn ($r) => $r > $to), 0, 2));
        }
        $previewRows = array_values(array_unique($previewRows));
        sort($previewRows);

        $preview = [];
        foreach ($previewRows as $r) {
            $kind = $r < $a->headerStart ? 'title' : ($r <= $a->headerEnd ? 'header' : ($a->rowKinds[$r] ?? 'blank'));
            if ($kind === 'blank' || ($kind === 'title' && $grid->isBlankRow($r))) {
                continue;
            }
            $preview[] = [
                'row' => $r,
                'kind' => $kind,
                'cells' => array_map(fn (int $c) => mb_strimwidth($grid->cell($r, $c)->text(), 0, 40, '…'), $cols),
            ];
        }

        return [
            'name' => $grid->name,
            'header_start' => $a->headerStart,
            'header_end' => $a->headerEnd,
            'highest_row' => $grid->highestRow,
            'data_rows' => count($a->dataRows()),
            'subtotal_rows' => $a->countKind(SheetAnalysis::KIND_SUBTOTAL),
            'total_rows' => $a->countKind(SheetAnalysis::KIND_TOTAL),
            'repeat_rows' => $a->countKind(SheetAnalysis::KIND_REPEAT),
            'check_rows' => $a->countKind(SheetAnalysis::KIND_CHECK),
            'columns' => array_values($a->columns),
            'column_letters' => array_map(fn (int $c) => Coordinate::stringFromColumnIndex($c), $cols),
            'title_lines' => $a->titleLines,
            'score' => $a->score,
            'preview' => $preview,
        ];
    }
}
