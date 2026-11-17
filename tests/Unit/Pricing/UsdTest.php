<?php

declare(strict_types=1);

namespace Tests\Unit\Pricing;

use App\Support\Usd;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UsdTest extends TestCase
{
    public function test_display_uses_four_decimals_only_when_exact(): void
    {
        $this->assertSame('$4.9255', Usd::display(4_925_500_000));
        $this->assertSame('$0.000000001', Usd::display(1));
        $this->assertSame('-$0.000000001', Usd::display(-1));
        $this->assertSame('$0.0000', Usd::display(0));
        $this->assertSame('-$9223372036.854775808', Usd::display(PHP_INT_MIN));
    }

    #[DataProvider('markedUpAmounts')]
    public function test_markup_keeps_exact_precision_without_integer_overflow(int $cost, string $expected): void
    {
        $this->assertSame($expected, Usd::markedUp($cost));
    }

    public static function markedUpAmounts(): array
    {
        return [
            [0, '$0.000000000'],
            [1, '$0.00000000125'],
            [2, '$0.0000000025'],
            [4, '$0.000000005'],
            [59_550_000, '$0.074437500'],
            [PHP_INT_MAX, '$11529215046.06846975875'],
        ];
    }

    #[DataProvider('formattedAmounts')]
    public function test_it_formats_exact_amounts(int $amount, int $decimals, string $expected): void
    {
        $this->assertSame($expected, Usd::format($amount, $decimals));
    }

    public static function formattedAmounts(): array
    {
        return [
            [74_500_000, 4, '$0.0745'], [-74_500_000, 4, '-$0.0745'],
            [5_000_000_000, 4, '$5.0000'], [0, 4, '$0.0000'],
            [5_000_000_000, 0, '$5'], [59_550_000, 5, '$0.05955'],
            [1, 9, '$0.000000001'], [1, 10, '$0.0000000010'],
            [PHP_INT_MAX, 9, '$9223372036.854775807'],
            [PHP_INT_MIN, 9, '-$9223372036.854775808'],
        ];
    }

    public function test_four_decimal_places_are_the_default(): void
    {
        $this->assertSame('$0.0745', Usd::format(74_500_000));
    }

    #[DataProvider('inexactAmounts')]
    public function test_it_rejects_silent_rounding(int $amount, int $decimals): void
    {
        $this->expectException(InvalidArgumentException::class);

        Usd::format($amount, $decimals);
    }

    public static function inexactAmounts(): array
    {
        return [[59_550_000, 4], [-59_550_000, 4], [1, 4], [1, 0], [0, -1]];
    }

    public function test_exact_format_always_has_nine_decimal_places(): void
    {
        $this->assertSame('$0.059550000', Usd::exact(59_550_000));
        $this->assertSame('-$0.059550000', Usd::exact(-59_550_000));
        $this->assertSame('$0.000000000', Usd::exact(0));
    }
}
