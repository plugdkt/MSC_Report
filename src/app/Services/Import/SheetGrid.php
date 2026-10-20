<?php

namespace App\Services\Import;

/**
 * ตารางเซลล์ของหนึ่งชีต — index แถว/คอลัมน์เริ่มที่ 1 ตาม Excel
 */
final class SheetGrid
{
    /**
     * @param  array<int, array<int, Cell>>  $rows
     * @param  list<array{0:int,1:int,2:int,3:int}>  $merges  [col1,row1,col2,row2]
     */
    public function __construct(
        public readonly string $name,
        public readonly array $rows,
        public readonly int $highestRow,
        public readonly int $highestColumn,
        public readonly array $merges = [],
    ) {}

    public function cell(int $row, int $col): Cell
    {
        return $this->rows[$row][$col] ?? new Cell(null);
    }

    /**
     * @return array<int, Cell>
     */
    public function row(int $row): array
    {
        return $this->rows[$row] ?? [];
    }

    public function isBlankRow(int $row): bool
    {
        foreach ($this->row($row) as $cell) {
            if (! $cell->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * เซลล์ที่ไม่ว่าง เรียงตามคอลัมน์
     *
     * @return array<int, Cell>
     */
    public function filled(int $row): array
    {
        return array_filter($this->row($row), fn (Cell $c) => ! $c->isEmpty());
    }

    /**
     * ค่าในเซลล์ที่ถูก merge แนวนอน (หัวตาราง) ให้ใช้ค่าจากเซลล์แรกของกลุ่ม
     */
    public function mergedText(int $row, int $col): string
    {
        $text = $this->cell($row, $col)->text();
        if ($text !== '') {
            return $text;
        }
        foreach ($this->merges as [$c1, $r1, $c2, $r2]) {
            if ($row >= $r1 && $row <= $r2 && $col >= $c1 && $col <= $c2) {
                return $this->cell($r1, $c1)->text();
            }
        }

        return '';
    }
}
