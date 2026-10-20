<?php

namespace App\Services\Import;

/**
 * ค่าหนึ่งเซลล์ที่อ่านจาก Excel แล้ว (ค่าที่คำนวณแล้ว + ข้อมูลประกอบที่ใช้จำแนกแถว)
 */
final class Cell
{
    public function __construct(
        public readonly string|int|float|bool|null $value,
        public readonly bool $isText = false,
        public readonly bool $isDate = false,
        public readonly ?string $formula = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->value === null || (is_string($this->value) && trim($this->value) === '');
    }

    public function isNumber(): bool
    {
        return ! $this->isText && ! $this->isDate && (is_int($this->value) || is_float($this->value));
    }

    public function text(): string
    {
        if ($this->value === null) {
            return '';
        }
        if (is_bool($this->value)) {
            return $this->value ? 'TRUE' : 'FALSE';
        }
        if (is_float($this->value)) {
            return rtrim(rtrim(number_format($this->value, 2, '.', ''), '0'), '.');
        }

        return trim((string) $this->value);
    }

    public function hasAggregateFormula(): bool
    {
        return $this->formula !== null && preg_match('/\b(SUBTOTAL|SUM|AGGREGATE)\s*\(/i', $this->formula) === 1;
    }
}
