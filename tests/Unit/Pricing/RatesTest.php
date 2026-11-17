<?php

namespace Tests\Unit\Pricing;

use App\Pricing\InvalidRate;
use App\Pricing\Rates;
use PHPUnit\Framework\Attributes\DataProvider;

class RatesTest extends PricingTestCase
{
    public function test_it_converts_rates_to_nanodollars_per_token_and_preserves_the_strings(): void
    {
        $input = self::rates();
        $input['input_text_tokens'] = '05.00000';
        $rates = Rates::fromArray($input);

        $this->assertSame(5_000, $rates->inputTextTokens);
        $this->assertSame(10_000, $rates->inputImageTokens);
        $this->assertSame(40_000, $rates->outputTokens);
        $this->assertSame(12_500, $rates->reasoningTokens);
        $this->assertSame($input, $rates->toArray());
    }

    #[DataProvider('invalidRates')]
    public function test_it_rejects_invalid_rates(mixed $value): void
    {
        $input = self::rates();
        $input['output_tokens'] = $value;
        $this->expectException(InvalidRate::class);

        Rates::fromArray($input);
    }

    public static function invalidRates(): array
    {
        return [['0.0001'], ['-5'], ['5e0'], [' 5'], ['5.'], [''], [null], [5], [(string) PHP_INT_MAX]];
    }

    #[DataProvider('requiredKeys')]
    public function test_it_requires_every_rate(string $key): void
    {
        $input = self::rates();
        unset($input[$key]);
        $this->expectException(InvalidRate::class);

        Rates::fromArray($input);
    }

    public static function requiredKeys(): array
    {
        return array_map(fn (string $key): array => [$key], array_keys(self::rates()));
    }

    public function test_zero_and_single_nanodollar_rates_are_exact(): void
    {
        $input = self::rates();
        $input['input_text_tokens'] = '0';
        $input['reasoning_tokens'] = '0.001';

        $rates = Rates::fromArray($input);

        $this->assertSame(0, $rates->inputTextTokens);
        $this->assertSame(1, $rates->reasoningTokens);
    }
}
