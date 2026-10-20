<?php

namespace Tests\Unit;

use App\Services\Import\ValueNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValueNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0:string|int|float|null, 1:?float}>
     */
    public static function numbers(): array
    {
        return [
            'comma' => ['1,234.50', 1234.5],
            'baht sign' => ['฿12,000', 12000.0],
            'thai digits' => ['๑๒,๕๐๐', 12500.0],
            'parenthesis negative' => ['(1,500)', -1500.0],
            'percent' => ['12.5%', 12.5],
            'baht word' => ['500 บาท', 500.0],
            'already number' => [99.9, 99.9],
            'dash means empty' => ['-', null],
            'text' => ['ไม่มีข้อมูล', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_parse_number(string|int|float|null $input, ?float $expected): void
    {
        $this->assertSame($expected, ValueNormalizer::parseNumber($input));
    }

    /**
     * @return array<string, array{0:string, 1:?string}>
     */
    public static function dates(): array
    {
        return [
            'buddhist year slash' => ['15/03/2569', '2026-03-15'],
            'gregorian dash' => ['15-3-2026', '2026-03-15'],
            'iso buddhist' => ['2569-09-30', '2026-09-30'],
            'thai month abbr' => ['30 ก.ย. 2569', '2026-09-30'],
            'thai month full' => ['1 ตุลาคม 2568', '2025-10-01'],
            'short buddhist year' => ['30/09/69', '2026-09-30'],
            'thai digits' => ['๓๐/๐๙/๒๕๖๙', '2026-09-30'],
            'invalid day' => ['31/02/2569', null],
            'not a date' => ['ค่าซ่อมแซม', null],
        ];
    }

    #[DataProvider('dates')]
    public function test_parse_date(string $input, ?string $expected): void
    {
        $this->assertSame($expected, ValueNormalizer::parseDate($input));
    }

    public function test_clean_text_removes_non_breaking_spaces_and_converts_thai_digits(): void
    {
        $this->assertSame('รายการ 12', ValueNormalizer::cleanText("  รายการ\u{00A0}\u{00A0}๑๒ "));
    }
}
