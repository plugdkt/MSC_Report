<?php

namespace App\Services\Import;

/**
 * ผลวิเคราะห์โครงสร้างของชีต: หัวตาราง, ชื่อคอลัมน์, ชนิดของแต่ละแถว
 */
final class SheetAnalysis
{
    public const KIND_DATA = 'data';

    public const KIND_SUBTOTAL = 'subtotal';

    public const KIND_TOTAL = 'total';

    public const KIND_CHECK = 'check';

    public const KIND_BLANK = 'blank';

    public const KIND_FOOTER = 'footer';

    public const KIND_REPEAT = 'header_repeat';

    public const KIND_LABELS = [
        self::KIND_SUBTOTAL => 'แถวยอดรวมย่อย',
        self::KIND_TOTAL => 'แถวยอดรวมทั้งสิ้น',
        self::KIND_CHECK => 'แถวตรวจสอบท้ายตาราง',
        self::KIND_FOOTER => 'ส่วนท้าย/ลงนาม',
        self::KIND_REPEAT => 'หัวตารางซ้ำ (ขึ้นหน้าใหม่)',
    ];

    /**
     * @param  array<int, string>  $columns  คอลัมน์ Excel (index) => ชื่อหัวตาราง
     * @param  list<string>  $titleLines
     * @param  array<int, string>  $rowKinds  แถว Excel => KIND_*
     */
    public function __construct(
        public readonly string $sheet,
        public readonly int $headerStart,
        public readonly int $headerEnd,
        public readonly array $columns,
        public readonly array $titleLines,
        public readonly array $rowKinds,
        public readonly float $score,
    ) {}

    /**
     * @return list<int>
     */
    public function dataRows(): array
    {
        return array_keys(array_filter($this->rowKinds, fn (string $k) => $k === self::KIND_DATA));
    }

    public function countKind(string $kind): int
    {
        return count(array_filter($this->rowKinds, fn (string $k) => $k === $kind));
    }

    /**
     * @return list<int>
     */
    public function rowsOfKind(string $kind): array
    {
        return array_keys(array_filter($this->rowKinds, fn (string $k) => $k === $kind));
    }
}
