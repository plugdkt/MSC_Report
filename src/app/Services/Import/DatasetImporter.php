<?php

namespace App\Services\Import;

use App\Models\CodeSet;
use App\Models\Dataset;
use App\Models\DatasetColumn;
use App\Models\DatasetRow;
use App\Services\CodeLookup;
use App\Services\ExcelTemplate\TemplateRegistry;
use App\Services\ExcelTemplate\TemplateWorkbookBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * นำเข้าชีตที่เลือก: ตัดแถวผลรวม แปลงชนิดข้อมูล วิเคราะห์คอลัมน์ ตรวจคุณภาพข้อมูล แล้วบันทึกลงฐานข้อมูล
 */
class DatasetImporter
{
    private const THAI_MONTHS = 'มกราคม|กุมภาพันธ์|มีนาคม|เมษายน|พฤษภาคม|มิถุนายน|กรกฎาคม|สิงหาคม|กันยายน|ตุลาคม|พฤศจิกายน|ธันวาคม';

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly SheetAnalyzer $analyzer,
        private readonly ColumnProfiler $profiler,
        private readonly CodeLookup $codes,
        private readonly TemplateRegistry $templates,
    ) {}

    public function import(Dataset $dataset, string $sheet, int $headerStart, int $headerEnd): void
    {
        $grid = $this->reader->readSheet(Storage::disk('local')->path($dataset->stored_path), $sheet);
        $analysis = $this->analyzer->analyze($grid, $headerStart, $headerEnd);
        $dataRows = $analysis->dataRows();

        if ($dataRows === []) {
            throw new ImportException('ไม่พบแถวข้อมูลใต้หัวตารางที่เลือก');
        }

        $overrides = $dataset->columns()->get()->keyBy(fn (DatasetColumn $c) => $c->position.'|'.$c->source_name);
        $fields = $this->templateFields($dataset, $sheet);

        $columns = [];
        $usedNames = [];
        $position = 0;
        foreach ($analysis->columns as $col => $header) {
            $position++;
            $cells = array_map(fn (int $r) => $grid->cell($r, $col), $dataRows);
            $field = $fields[$header] ?? null;
            $type = $field['type'] ?? $this->profiler->inferType($header, $cells);
            $old = $overrides->get($position.'|'.$header);
            $finalType = $old?->user_type ?? $type;
            $values = array_map(fn (Cell $c) => $this->profiler->convert($c, $finalType), $cells);

            $display = $old?->display_name ?? ($header !== '' ? $header : 'คอลัมน์ '.Coordinate::stringFromColumnIndex($col));
            $display = $this->uniqueName($display, $usedNames);
            $isEmpty = ! array_filter($values, fn ($v) => $v !== null && $v !== '');

            $columns[] = [
                'col' => $col,
                'attributes' => [
                    'position' => $position,
                    'slug' => 'c'.$position,
                    'source_name' => $header,
                    'display_name' => $display,
                    'inferred_type' => $type,
                    'user_type' => $old?->user_type,
                    'is_pii' => $old?->is_pii ?? $this->profiler->looksLikePii($header, $values),
                    'is_hidden' => $old?->is_hidden ?? $isEmpty,
                    'code_set_id' => in_array($finalType, ['code', 'category'], true)
                        ? ($field['code_set_id'] ?? $this->codeSetFromOptions($field) ?? $this->codes->setForColumn($header)?->id)
                        : null,
                    'profile' => $this->profiler->profile($finalType, $values),
                ],
                'values' => $values,
            ];
        }

        $excluded = $this->excludedRows($grid, $analysis);
        $quality = $this->qualityChecks($grid, $analysis, $columns, $dataRows);
        if ($fields !== []) {
            array_unshift($quality, $this->templateCheck($dataset, $fields, $analysis->columns));
        }

        DB::transaction(function () use ($dataset, $sheet, $analysis, $columns, $dataRows, $excluded, $quality) {
            $dataset->columns()->delete();
            DatasetRow::where('dataset_id', $dataset->id)->delete();

            foreach ($columns as $column) {
                $dataset->columns()->create($column['attributes']);
            }

            $batch = [];
            foreach ($dataRows as $i => $sourceRow) {
                $data = [];
                foreach ($columns as $column) {
                    $data[$column['attributes']['slug']] = $column['values'][$i];
                }
                $batch[] = [
                    'dataset_id' => $dataset->id,
                    'row_index' => $i + 1,
                    'source_row' => $sourceRow,
                    'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
                ];
                if (count($batch) === 500) {
                    DatasetRow::insert($batch);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                DatasetRow::insert($batch);
            }

            $dataset->update([
                'status' => 'ready',
                'sheet_name' => $sheet,
                'header_row_start' => $analysis->headerStart,
                'header_row_end' => $analysis->headerEnd,
                'title_lines' => $analysis->titleLines,
                'period_label' => $dataset->period_label
                    ?? $this->guessPeriod($analysis->titleLines)
                    ?? $this->guessPeriod(collect($dataset->inspection['sheets'] ?? [])->pluck('title_lines')->flatten()->all()),
                'row_count' => count($dataRows),
                'excluded_rows' => $excluded,
                'quality' => $quality,
                'error_message' => null,
            ]);
        });
    }

    /**
     * ค่าตั้งของคอลัมน์จากแบบฟอร์ม Excel แนะนำ (จับคู่ด้วยชื่อหัวตาราง)
     *
     * @return array<string, array<string, mixed>>
     */
    private function templateFields(Dataset $dataset, string $sheet): array
    {
        $key = $dataset->inspection['template']['key'] ?? null;
        if (! $key || $sheet !== TemplateWorkbookBuilder::DATA_SHEET) {
            return [];
        }
        $template = $this->templates->find($key);

        return $template ? collect($template['fields'])->keyBy('label')->all() : [];
    }

    /**
     * @param  array<string, mixed>|null  $field
     */
    private function codeSetFromOptions(?array $field): ?int
    {
        $from = $field['options_from'] ?? '';

        return str_starts_with($from, 'code_set:') ? CodeSet::where('key', substr($from, 9))->value('id') : null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<int, string>  $headers
     * @return array{level:string, message:string}
     */
    private function templateCheck(Dataset $dataset, array $fields, array $headers): array
    {
        $missing = array_keys(array_filter($fields, fn ($f, $label) => ($f['required'] ?? false) && ! in_array($label, $headers, true), ARRAY_FILTER_USE_BOTH));
        $matched = count(array_intersect(array_keys($fields), $headers));
        $name = $dataset->inspection['template']['name'];

        return $missing === []
            ? ['level' => 'ok', 'message' => "ไฟล์จากแบบฟอร์ม \"{$name}\" — ตั้งค่าคอลัมน์ให้อัตโนมัติ {$matched} คอลัมน์"]
            : ['level' => 'warn', 'message' => "ไฟล์จากแบบฟอร์ม \"{$name}\" แต่ไม่พบคอลัมน์ที่จำเป็น: ".implode(', ', $missing)];
    }

    /**
     * @param  array<int, true>  $used
     */
    private function uniqueName(string $name, array &$used): string
    {
        $candidate = $name;
        $i = 2;
        while (isset($used[$candidate])) {
            $candidate = "{$name} ({$i})";
            $i++;
        }
        $used[$candidate] = true;

        return $candidate;
    }

    /**
     * @return list<array{row:int, kind:string, label:string, text:string}>
     */
    private function excludedRows(SheetGrid $grid, SheetAnalysis $a): array
    {
        $out = [];
        foreach ($a->rowKinds as $row => $kind) {
            if (! isset(SheetAnalysis::KIND_LABELS[$kind]) || $kind === SheetAnalysis::KIND_FOOTER) {
                continue;
            }
            $texts = array_map(fn (Cell $c) => $c->text(), $grid->filled($row));
            $out[] = [
                'row' => $row,
                'kind' => $kind,
                'label' => SheetAnalysis::KIND_LABELS[$kind],
                'text' => mb_strimwidth(implode(' | ', $texts), 0, 120, '…'),
            ];
        }

        return $out;
    }

    /**
     * ตรวจคุณภาพข้อมูลทั่วไป (FR-2.2) — กฎเฉพาะ Template เช่น สมการสมดุล อยู่ใน M ถัดไป
     *
     * @param  list<array{col:int, attributes:array<string,mixed>, values:list<mixed>}>  $columns
     * @param  list<int>  $dataRows
     * @return list<array{level:string, message:string}>
     */
    private function qualityChecks(SheetGrid $grid, SheetAnalysis $a, array $columns, array $dataRows): array
    {
        $checks = [];

        $sub = $a->countKind(SheetAnalysis::KIND_SUBTOTAL);
        $tot = $a->countKind(SheetAnalysis::KIND_TOTAL);
        $rep = $a->countKind(SheetAnalysis::KIND_REPEAT);
        $chk = $a->countKind(SheetAnalysis::KIND_CHECK);
        if ($sub + $tot + $rep + $chk > 0) {
            $parts = array_filter([
                $sub ? "ยอดรวมย่อย {$sub} แถว" : null,
                $tot ? "ยอดรวมทั้งสิ้น {$tot} แถว" : null,
                $rep ? "หัวตารางซ้ำ {$rep} แถว" : null,
                $chk ? "แถวตรวจสอบ {$chk} แถว" : null,
            ]);
            $checks[] = ['level' => 'info', 'message' => 'ตัดออกจากการคำนวณ: '.implode(', ', $parts).' (เพื่อไม่ให้นับซ้ำ)'];
        }

        $totalRows = $a->rowsOfKind(SheetAnalysis::KIND_TOTAL);
        $totalRow = $totalRows === [] ? null : end($totalRows);
        $matched = [];
        foreach ($columns as $column) {
            $attr = $column['attributes'];
            $type = $attr['user_type'] ?? $attr['inferred_type'];
            if (! in_array($type, ['currency', 'number', 'integer'], true)) {
                continue;
            }
            $sum = round(array_sum(array_map('floatval', array_filter($column['values'], fn ($v) => $v !== null))), 2);

            if ($totalRow !== null) {
                $fileTotal = ValueNormalizer::parseNumber($grid->cell($totalRow, $column['col'])->value);
                if ($fileTotal !== null) {
                    if (abs($fileTotal - $sum) <= 0.05) {
                        $matched[] = $attr['display_name'];
                    } else {
                        $checks[] = ['level' => 'warn', 'message' => sprintf('ผลรวม "%s" ที่ระบบคำนวณ (%s) ไม่ตรงกับแถวยอดรวมในไฟล์ (%s) ต่างกัน %s',
                            $attr['display_name'], number_format($sum, 2), number_format($fileTotal, 2), number_format($sum - $fileTotal, 2))];
                    }
                }
            }

            $negatives = $attr['profile']['negatives'] ?? 0;
            $canBeNegative = preg_match('/(โอน|ปรับปรุง|ผลต่าง|เปลี่ยนแปลง|เพิ่ม\/ลด|กำไร|ขาดทุน)/u', $attr['source_name']) === 1;
            if ($negatives > 0 && $type === 'currency' && ! $canBeNegative) {
                $checks[] = ['level' => 'warn', 'message' => "คอลัมน์ \"{$attr['display_name']}\" มีค่าติดลบ {$negatives} แถว — ตรวจสอบว่าถูกต้องหรือไม่"];
            }
        }
        if ($matched !== []) {
            array_unshift($checks, ['level' => 'ok', 'message' => 'ผลรวมที่ระบบคำนวณตรงกับแถวยอดรวมในไฟล์: '.implode(', ', $matched)]);
        }

        $signatures = [];
        foreach ($dataRows as $i => $r) {
            $signatures[] = json_encode(array_map(fn ($c) => $c['values'][$i], $columns), JSON_UNESCAPED_UNICODE);
        }
        $dups = count($signatures) - count(array_unique($signatures));
        if ($dups > 0) {
            $checks[] = ['level' => 'warn', 'message' => "พบแถวที่ข้อมูลซ้ำกันทุกคอลัมน์ {$dups} แถว"];
        }

        $hidden = array_filter($columns, fn ($c) => $c['attributes']['is_hidden']);
        if ($hidden !== []) {
            $checks[] = ['level' => 'info', 'message' => 'ซ่อนคอลัมน์ที่ไม่มีข้อมูล '.count($hidden).' คอลัมน์'];
        }

        $pii = array_filter($columns, fn ($c) => $c['attributes']['is_pii']);
        if ($pii !== []) {
            $checks[] = ['level' => 'warn', 'message' => 'คอลัมน์ที่อาจเป็นข้อมูลส่วนบุคคล (จะถูกปิดบังก่อนส่งให้ AI): '.implode(', ', array_map(fn ($c) => $c['attributes']['display_name'], $pii))];
        }

        return $checks;
    }

    /**
     * @param  list<string>  $titleLines
     */
    private function guessPeriod(array $titleLines): ?string
    {
        foreach ($titleLines as $line) {
            if (preg_match('/('.self::THAI_MONTHS.')\s*(25\d\d)/u', $line, $m)) {
                return $m[1].' '.$m[2];
            }
        }

        return null;
    }
}
