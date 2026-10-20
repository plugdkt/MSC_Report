<?php

namespace App\Services\Import;

use PhpOffice\PhpSpreadsheet\Cell\Cell as PhpCell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * อ่านไฟล์ .xlsx / .xls / .csv เป็น SheetGrid (ใช้ค่าที่คำนวณแล้วของสูตร)
 */
class SpreadsheetReader
{
    public const MAX_ROWS = 200000;

    public const MAX_COLUMNS = 100;

    /**
     * @return list<string>
     */
    public function sheetNames(string $path): array
    {
        return $this->load($path)->getSheetNames();
    }

    /**
     * @return list<SheetGrid>
     */
    public function readAll(string $path): array
    {
        $book = $this->load($path);
        $grids = [];
        foreach ($book->getWorksheetIterator() as $sheet) {
            $grids[] = $this->toGrid($sheet);
        }
        $book->disconnectWorksheets();

        return $grids;
    }

    public function readSheet(string $path, string $sheetName): SheetGrid
    {
        $book = $this->load($path);
        $sheet = $book->getSheetByName($sheetName) ?? throw new ImportException("ไม่พบชีต \"{$sheetName}\" ในไฟล์");
        $grid = $this->toGrid($sheet);
        $book->disconnectWorksheets();

        return $grid;
    }

    private function load(string $path): Spreadsheet
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            if ($reader instanceof Csv) {
                $reader->setInputEncoding(Csv::GUESS_ENCODING);
                $reader->setFallbackEncoding('TIS-620');
            }

            return $reader->load($path);
        } catch (Throwable $e) {
            throw new ImportException('อ่านไฟล์ไม่ได้ — ไฟล์อาจเสียหายหรือไม่ใช่ไฟล์ Excel/CSV', previous: $e);
        }
    }

    private function toGrid(Worksheet $sheet): SheetGrid
    {
        $highestRow = min($sheet->getHighestDataRow(), self::MAX_ROWS);
        $highestCol = min(Coordinate::columnIndexFromString($sheet->getHighestDataColumn()), self::MAX_COLUMNS);

        $rows = [];
        for ($r = 1; $r <= $highestRow; $r++) {
            for ($c = 1; $c <= $highestCol; $c++) {
                $phpCell = $sheet->getCell([$c, $r]);
                $cell = $this->toCell($phpCell);
                if (! $cell->isEmpty()) {
                    $rows[$r][$c] = $cell;
                }
            }
        }

        $merges = [];
        foreach ($sheet->getMergeCells() as $range) {
            [$start, $end] = Coordinate::rangeBoundaries($range);
            $merges[] = [$start[0], $start[1], $end[0], $end[1]];
        }

        return new SheetGrid($sheet->getTitle(), $rows, $highestRow, $highestCol, $merges);
    }

    private function toCell(PhpCell $cell): Cell
    {
        $formula = $cell->isFormula() ? strtoupper((string) $cell->getValue()) : null;

        if ($formula !== null) {
            try {
                $value = $cell->getCalculatedValue();
            } catch (Throwable) {
                $value = $cell->getOldCalculatedValue();
            }
        } else {
            $value = $cell->getValue();
        }

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if ((is_int($value) || is_float($value)) && Date::isDateTime($cell)) {
            try {
                return new Cell(Date::excelToDateTimeObject($value)->format('Y-m-d'), isDate: true, formula: $formula);
            } catch (Throwable) {
                // ไม่ใช่วันที่จริง ใช้เป็นตัวเลขต่อ
            }
        }

        if (is_string($value)) {
            return new Cell(ValueNormalizer::cleanText($value), isText: $cell->getDataType() === DataType::TYPE_STRING || $formula !== null, formula: $formula);
        }

        if (is_float($value)) {
            $value = round($value, 6);
        }

        return new Cell($value, formula: $formula);
    }
}
