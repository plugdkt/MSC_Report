<?php

namespace Tests\Support;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * สร้างไฟล์ Excel ทดสอบที่เลียนแบบโครงสร้างไฟล์รายงานเงินคงเหลือจริง (ข้อมูลสมมติ):
 * - ชีต "รายงาน": หัวรายงาน 3 แถว, หัวตารางแถว 5, แถวยอดรวมย่อย (SUBTOTAL), ยอดรวมทั้งสิ้น, แถวตรวจสอบ, ส่วนลงนาม
 * - ชีต "ข้อมูล": ตารางเรียบ หัวแถวเดียว + แถว "รวม" ท้ายตาราง
 * - ชีต "สรุป": หัวตาราง 3 แถว (คำต่อท้าย + แถวสัญลักษณ์ (+) (-))
 */
class BudgetWorkbook
{
    /** ยอดรวมที่คาดหวังของข้อมูลสมมติ */
    public const TOTAL_BUDGET = 381500.0;

    public const TOTAL_USED = 247700.5;

    public const TOTAL_REMAINING = 133799.5;

    public static function create(string $path): string
    {
        $book = new Spreadsheet;
        self::reportSheet($book->getActiveSheet());
        self::flatSheet($book->createSheet());
        self::summarySheet($book->createSheet());

        (new Xlsx($book))->save($path);

        return $path;
    }

    /**
     * @return list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:float|string,7:float|string,8:float}>
     */
    private static function rows(): array
    {
        // ปี, รหัส, หมวด, กองทุน, รายการ, ณ วันที่ (พ.ศ. แบบข้อความ), งบประมาณ, ใช้จริง, คงเหลือ
        return [
            ['2569', '110000', '1.1 ค่าจ้างชั่วคราว', '20200', 'ค่าจ้างรายเดือน', '30/09/2569', 120000, 120000, 0],
            ['2569', '110000', '1.1 ค่าจ้างชั่วคราว', '20200', 'ค่าจ้างรายวัน', '30/09/2569', 45000, '๑๒๐๐๐', 33000],
            ['2569', '210200', '2.1.2 ค่าใช้สอย', '20200', 'ค่าซ่อมแซม', '30/09/2569', 80500, '1,500.50', 78999.5],
            ['2569', '210200', '2.1.2 ค่าใช้สอย', '50500', 'ค่าเบี้ยเลี้ยง', '30/09/2569', 136000, 114200, 21800],
        ];
    }

    private static function reportSheet($s): void
    {
        $s->setTitle('รายงาน');
        $s->setCellValue('A1', 'รายงานผลการใช้จ่ายงบประมาณ ประจำปีงบประมาณ พ.ศ. 2569');
        $s->setCellValue('A2', 'ประจำเดือน กันยายน 2569');
        $s->setCellValue('A3', 'หน่วยงาน ตัวอย่าง');
        foreach (['A1:I1', 'A2:I2', 'A3:I3'] as $m) {
            $s->mergeCells($m);
        }
        $s->fromArray(['ปี', 'รหัสงบประมาณ', 'คำอธิบาย', 'กองทุน', 'รายการ', 'ณ วันที่', 'งบประมาณ', 'ใช้จริง', 'คงเหลือ'], null, 'A5');

        $r = 6;
        $groups = [[0, 1], [2, 3]];
        $subtotalRows = [];
        foreach ($groups as $g) {
            $first = $r;
            foreach ($g as $i) {
                self::writeRow($s, $r++, self::rows()[$i]);
            }
            $s->setCellValue("E{$r}", 'ยอดรวม');
            foreach (['G', 'H', 'I'] as $c) {
                $s->setCellValue("{$c}{$r}", "=SUBTOTAL(9,{$c}{$first}:{$c}".($r - 1).')');
            }
            $subtotalRows[] = $r++;
        }
        $s->setCellValue("A{$r}", 'รวม');
        $s->setCellValue("E{$r}", 'ยอดรวมทั้งสิ้น');
        $s->setCellValue("G{$r}", '=SUBTOTAL(9,G6:G'.($r - 1).')');
        $s->setCellValue("H{$r}", '=SUBTOTAL(9,H6:H'.($r - 1).')');
        $s->setCellValue("I{$r}", '=SUBTOTAL(9,I6:I'.($r - 1).')');
        $totalRow = $r++;
        $s->setCellValue("G{$r}", "=G{$totalRow}");
        $s->setCellValue("H{$r}", "=H{$totalRow}+I{$totalRow}");
        $r += 3;
        $s->setCellValue("A{$r}", 'ผู้รายงาน........................');
        $s->setCellValue('A'.($r + 1), '(นักวิชาการเงินและบัญชี)');
    }

    private static function writeRow($s, int $r, array $row): void
    {
        foreach ($row as $i => $v) {
            $col = chr(65 + $i);
            if (is_string($v)) {
                $s->setCellValueExplicit("{$col}{$r}", $v, DataType::TYPE_STRING);
            } else {
                $s->setCellValue("{$col}{$r}", $v);
            }
        }
    }

    private static function flatSheet($s): void
    {
        $s->setTitle('ข้อมูล');
        $s->fromArray(['ปี', 'รหัสงบประมาณ', 'คำอธิบาย', 'กองทุน', 'รายการ', 'ณ วันที่', 'งบประมาณ', 'ใช้จริง', 'คงเหลือ'], null, 'A1');
        $r = 2;
        foreach (self::rows() as $row) {
            self::writeRow($s, $r++, $row);
        }
        $s->setCellValue("A{$r}", 'รวม');
        $s->setCellValue("G{$r}", '=SUM(G2:G'.($r - 1).')');
    }

    private static function summarySheet($s): void
    {
        $s->setTitle('สรุป');
        $s->setCellValue('A1', 'แบบรายงานสรุป');
        $s->fromArray(['ประเภทรายจ่าย', 'จำนวนงบประมาณที่', 'ยอดเงินใช้จริง', 'ยอดเงินคงเหลือ'], null, 'A5');
        $s->fromArray([null, 'ได้รับจัดสรร', null, null], null, 'A6');
        $s->fromArray([null, '(+)', '(-)', '(=)'], null, 'A7');
        $s->fromArray(['ค่าจ้างชั่วคราว', 165000, 132000, 33000], null, 'A8');
        $s->fromArray(['ค่าใช้สอย', 216500, 115700.5, 100799.5], null, 'A9');
        $s->fromArray(['ยอดรวมทั้งสิ้น', '=SUM(B8:B9)', '=SUM(C8:C9)', '=SUM(D8:D9)'], null, 'A10');
    }
}
