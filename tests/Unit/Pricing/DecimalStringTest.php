<?php

namespace Tests\Unit\Pricing;

use App\Support\DecimalString;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DecimalStringTest extends TestCase
{
    #[DataProvider('validDecimals')]
    public function test_it_scales_decimal_strings_exactly(string $value, int $scale, int $expected): void
    {
        $this->assertSame($expected, DecimalString::toScaledInt($value, $scale));
    }

    public static function validDecimals(): array
    {
        return [
            ['12.50', 3, 12_500],
            ['0.169080000', 9, 169_080_000],
            ['5', 3, 5_000],
            ['1.2300', 2, 123],
            ['0001.2300', 2, 123],
            ['1.000', 0, 1],
            ['0.000000', 3, 0],
            ['0', PHP_INT_MAX, 0],
            [(string) PHP_INT_MAX, 0, PHP_INT_MAX],
        ];
    }

    #[DataProvider('invalidDecimals')]
    public function test_it_rejects_invalid_inexact_or_overflowing_values(string $value, int $scale): void
    {
        $this->expectException(InvalidArgumentException::class);

        DecimalString::toScaledInt($value, $scale);
    }

    public static function invalidDecimals(): array
    {
        return [
            ['', 3], ['-1', 3], ['+1', 3], ['1e3', 3], [' 1', 3],
            ['1 ', 3], ["1\n", 3], ['.1', 3], ['1.', 3], ['1.2.3', 3],
            ['１２', 3], ['0.0001', 3], ['1.2301', 2], ['1', -1],
            [(string) PHP_INT_MAX.'0', 0], [(string) PHP_INT_MAX, 1],
            [substr((string) PHP_INT_MAX, 0, -1).'8', 0], ['1', PHP_INT_MAX],
        ];
    }
}
