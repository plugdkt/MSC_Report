<?php

namespace App\Services\ExcelTemplate;

use App\Models\CodeSet;
use App\Models\DatasetColumn;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Document\Properties;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * สร้างไฟล์ Excel แนะนำ: ชีตข้อมูล (หัวแถวเดียว + dropdown/ตรวจรูปแบบ), ชีตคำอธิบาย, ชีตรายการตัวเลือก (ซ่อน)
 * และฝังรหัส Template ใน document properties เพื่อให้ระบบจำได้ตอนอัปโหลดกลับ (spec FR-14)
 */
class TemplateWorkbookBuilder
{
    public const PROPERTY = 'msc_template';

    public const DATA_SHEET = 'ข้อมูล';

    private const VALIDATED_ROWS = 2000;

    private const TYPE_LABELS = DatasetColumn::TYPES;

    /**
     * @param  array{name:string, description?:string, fields:list<array<string,mixed>>}  $template
     */
    public function build(string $key, array $template): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->getProperties()
            ->setTitle($template['name'])
            ->setCreator('MSC Report')
            ->setCustomProperty(self::PROPERTY, $key, Properties::PROPERTY_TYPE_STRING);
        $book->getDefaultStyle()->getFont()->setName('TH Sarabun New')->setSize(16);

        $fields = array_map(fn (array $f) => $f + ['options' => $this->resolveOptions($f)], $template['fields']);

        $data = $book->getActiveSheet()->setTitle(self::DATA_SHEET);
        $lists = $book->createSheet()->setTitle('รายการตัวเลือก');
        $help = $book->createSheet()->setTitle('คำอธิบาย');

        $this->dataSheet($data, $fields);
        $this->listSheet($lists, $fields, $data);
        $this->helpSheet($help, $template, $fields);

        $lists->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
        $book->setActiveSheetIndex(0);

        return $book;
    }

    public function save(Spreadsheet $book, string $path): string
    {
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    /**
     * @param  array<string,mixed>  $field
     * @return list<string>
     */
    private function resolveOptions(array $field): array
    {
        if (! empty($field['options'])) {
            return array_values(array_map('strval', $field['options']));
        }
        if (isset($field['options_from']) && str_starts_with($field['options_from'], 'code_set:')) {
            $set = CodeSet::where('key', substr($field['options_from'], 9))->first();

            return $set ? $set->values()->pluck('code')->map(fn ($c) => (string) $c)->all() : [];
        }

        return [];
    }

    /**
     * @param  list<array<string,mixed>>  $fields
     */
    private function dataSheet(Worksheet $s, array $fields): void
    {
        foreach ($fields as $i => $f) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $s->setCellValueExplicit("{$col}1", (string) $f['label'], DataType::TYPE_STRING);
            $s->getColumnDimension($col)->setWidth($f['width'] ?? 16);
            $range = "{$col}2:{$col}".self::VALIDATED_ROWS;

            match ($f['type']) {
                'currency', 'number' => $s->getStyle($range)->getNumberFormat()->setFormatCode('#,##0.00'),
                'integer' => $s->getStyle($range)->getNumberFormat()->setFormatCode('#,##0'),
                'date' => $s->getStyle($range)->getNumberFormat()->setFormatCode('dd/mm/yyyy'),
                'code' => $s->getStyle($range)->getNumberFormat()->setFormatCode('@'),
                default => null,
            };

            $validation = match ($f['type']) {
                'currency', 'number', 'percent' => $this->numberValidation($f),
                'integer' => $this->numberValidation($f, DataValidation::TYPE_WHOLE),
                'date' => $this->dateValidation($f),
                default => null,
            };
            if ($validation) {
                $s->setDataValidation($range, $validation);
            }
        }

        $last = Coordinate::stringFromColumnIndex(count($fields));
        $s->getStyle("A1:{$last}1")->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3B6E']],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ]);
        $s->getRowDimension(1)->setRowHeight(30);
        $s->freezePane('A2');
        $s->setAutoFilter("A1:{$last}1");
    }

    /**
     * @param  list<array<string,mixed>>  $fields
     */
    private function listSheet(Worksheet $lists, array $fields, Worksheet $data): void
    {
        $listCol = 0;
        foreach ($fields as $i => $f) {
            if ($f['options'] === []) {
                continue;
            }
            $listCol++;
            $lc = Coordinate::stringFromColumnIndex($listCol);
            $lists->setCellValueExplicit("{$lc}1", (string) $f['label'], DataType::TYPE_STRING);
            foreach ($f['options'] as $r => $option) {
                $lists->setCellValueExplicit($lc.($r + 2), $option, DataType::TYPE_STRING);
            }

            $v = new DataValidation;
            $v->setType(DataValidation::TYPE_LIST)
                ->setErrorStyle(DataValidation::STYLE_WARNING)
                ->setAllowBlank(! ($f['required'] ?? false))
                ->setShowDropDown(true)
                ->setShowErrorMessage(true)
                ->setErrorTitle('ค่าไม่อยู่ในรายการ')
                ->setError('กรุณาเลือกจากรายการ (กดลูกศรด้านขวาของช่อง)')
                ->setShowInputMessage(true)
                ->setPromptTitle($f['label'])
                ->setPrompt('เลือกจากรายการ')
                ->setFormula1("'รายการตัวเลือก'!\${$lc}\$2:\${$lc}\$".(count($f['options']) + 1));
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $data->setDataValidation("{$col}2:{$col}".self::VALIDATED_ROWS, $v);
        }
    }

    /**
     * @param  array{name:string, description?:string}  $template
     * @param  list<array<string,mixed>>  $fields
     */
    private function helpSheet(Worksheet $s, array $template, array $fields): void
    {
        $s->setCellValueExplicit('A1', (string) $template['name'], DataType::TYPE_STRING);
        $s->getStyle('A1')->getFont()->setBold(true)->setSize(22);
        $s->setCellValueExplicit('A2', (string) ($template['description'] ?? ''), DataType::TYPE_STRING);
        $s->setCellValue('A4', 'วิธีใช้: กรอกข้อมูลในชีต "ข้อมูล" ตั้งแต่แถวที่ 2 ลงไป ห้ามแก้ชื่อหัวตาราง ห้ามแทรกแถวยอดรวม แล้วอัปโหลดไฟล์นี้กลับเข้าระบบ MSC Report');
        $s->setCellValue('A5', 'ไฟล์นี้มีรหัสแบบฟอร์มฝังอยู่ ระบบจะรู้จักคอลัมน์ทั้งหมดอัตโนมัติ');

        $s->fromArray(['คอลัมน์', 'ชนิดข้อมูล', 'จำเป็น', 'วิธีกรอก', 'ตัวอย่าง'], null, 'A7');
        $s->getStyle('A7:E7')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F3B6E']],
        ]);
        $row = 8;
        foreach ($fields as $f) {
            $how = trim(($f['help'] ?? '').($f['options'] !== [] ? ' (มีรายการให้เลือก '.count($f['options']).' ค่า)' : ''));
            $s->fromArray([$f['label'], self::TYPE_LABELS[$f['type']] ?? $f['type'], ($f['required'] ?? false) ? 'ใช่' : '', $how, (string) ($f['example'] ?? '')], null, "A{$row}");
            $s->getCell("E{$row}")->setValueExplicit((string) ($f['example'] ?? ''), DataType::TYPE_STRING);
            $row++;
        }
        foreach (['A' => 28, 'B' => 14, 'C' => 9, 'D' => 60, 'E' => 24] as $c => $w) {
            $s->getColumnDimension($c)->setWidth($w);
        }
        $s->getStyle("D8:D{$row}")->getAlignment()->setWrapText(true);
    }

    /**
     * @param  array<string,mixed>  $f
     */
    private function numberValidation(array $f, string $type = DataValidation::TYPE_DECIMAL): DataValidation
    {
        $v = new DataValidation;
        $min = $f['type'] === 'percent' ? 0 : -1e12;
        $max = $f['type'] === 'percent' ? 100 : 1e12;

        return $v->setType($type)
            ->setOperator(DataValidation::OPERATOR_BETWEEN)
            ->setFormula1((string) $min)->setFormula2((string) $max)
            ->setAllowBlank(true)
            ->setErrorStyle(DataValidation::STYLE_STOP)
            ->setShowErrorMessage(true)
            ->setErrorTitle('ต้องเป็นตัวเลข')
            ->setError($f['type'] === 'percent' ? 'กรอกตัวเลข 0–100' : 'กรอกเฉพาะตัวเลข (ไม่ต้องใส่ "บาท")');
    }

    /**
     * @param  array<string,mixed>  $f
     */
    private function dateValidation(array $f): DataValidation
    {
        $v = new DataValidation;

        return $v->setType(DataValidation::TYPE_DATE)
            ->setOperator(DataValidation::OPERATOR_GREATERTHAN)
            ->setFormula1('18264') // 1 ม.ค. 1950
            ->setAllowBlank(true)
            ->setErrorStyle(DataValidation::STYLE_WARNING)
            ->setShowErrorMessage(true)
            ->setErrorTitle('รูปแบบวันที่')
            ->setError('กรอกวันที่ เช่น 15/03/2026 หรือพิมพ์ "15/03/2569" เป็นข้อความก็ได้');
    }
}
