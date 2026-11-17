<?php

namespace Tests\Unit\Pricing;

use App\Pricing\PriceCalculator;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class PriceCalculatorTest extends PricingTestCase
{
    public function test_the_documentation_example_costs_exactly_373875000_nanodollars(): void
    {
        $calculator = new PriceCalculator;
        $usage = new TokenUsage(115, 2 * 765, 6450, 8000);

        $this->assertSame(373_875_000, $calculator->providerCost($usage, Rates::fromArray(self::rates())));
    }

    public function test_the_medium_square_example_has_the_exact_cost_and_rounded_price(): void
    {
        $calculator = new PriceCalculator;
        $cost = $calculator->providerCost(new TokenUsage(25, 0, 1100, 1234), Rates::fromArray(self::rates()));

        $this->assertSame(59_550_000, $cost);
        $this->assertSame(74_500_000, $calculator->userPrice($cost));
    }

    public function test_it_uses_the_supplied_rate_snapshot(): void
    {
        $calculator = new PriceCalculator;
        $usage = new TokenUsage(25, 0, 1100, 1234);
        $snapshot = Rates::fromArray(self::rates());
        $newRates = Rates::fromArray([
            'input_text_tokens' => '10.00', 'input_image_tokens' => '20.00',
            'output_tokens' => '80.00', 'reasoning_tokens' => '25.00',
        ]);

        $this->assertSame(119_100_000, $calculator->providerCost($usage, $newRates));
        $this->assertSame(59_550_000, $calculator->providerCost($usage, $snapshot));
    }

    #[DataProvider('roundingCases')]
    public function test_user_price_rounds_up_to_the_next_100000_nanodollars(int $cost, int $price): void
    {
        $this->assertSame($price, (new PriceCalculator)->userPrice($cost));
    }

    public static function roundingCases(): array
    {
        return [[0, 0], [400_000, 500_000], [1, 100_000], [80_000, 100_000], [80_001, 200_000]];
    }

    public function test_user_price_rejects_negative_costs(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PriceCalculator)->userPrice(-1);
    }

    public function test_it_estimates_the_documented_medium_square_range(): void
    {
        $estimate = (new PriceCalculator)->estimate(str_repeat('a', 100), '1024x1024', 'medium', 0, self::table());

        $this->assertEquals(new TokenUsage(21, 0, 1100, 200), $estimate->minTokens);
        $this->assertEquals(new TokenUsage(29, 0, 1100, 2000), $estimate->maxTokens);
        $this->assertSame(46_605_000, $estimate->minProviderCost);
        $this->assertSame(69_145_000, $estimate->maxProviderCost);
        $this->assertSame(58_300_000, $estimate->minPrice);
        $this->assertSame(86_500_000, $estimate->maxPrice);
    }

    public function test_it_estimates_reference_images_and_the_high_quality_documentation_example(): void
    {
        $estimate = (new PriceCalculator)->estimate(str_repeat('a', 400), '1536x1024', 'high', 2, self::table());

        $this->assertEquals(new TokenUsage(85, 1530, 6450, 1000), $estimate->minTokens);
        $this->assertEquals(new TokenUsage(115, 1530, 6450, 8000), $estimate->maxTokens);
        $this->assertSame(373_875_000, $estimate->maxProviderCost);
        $this->assertSame(467_400_000, $estimate->maxPrice);
    }

    #[DataProvider('prompts')]
    public function test_text_estimates_count_unicode_characters_and_round_outwards(string $prompt, int $min, int $max): void
    {
        $estimate = (new PriceCalculator)->estimate($prompt, '1024x1024', 'low', 0, self::table());

        $this->assertSame($min, $estimate->minTokens->inputTextTokens);
        $this->assertSame($max, $estimate->maxTokens->inputTextTokens);
    }

    public static function prompts(): array
    {
        return [['', 0, 0], ['a', 0, 2], ['abcde', 1, 3], [str_repeat('é', 8), 1, 3], [str_repeat('😀', 8), 1, 3]];
    }

    public function test_it_rejects_negative_reference_image_counts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PriceCalculator)->estimate('prompt', '1024x1024', 'low', -1, self::table());
    }

    public function test_a_large_price_can_be_calculated_without_intermediate_overflow(): void
    {
        $units = intdiv(PHP_INT_MAX, 100_000);

        $this->assertSame($units * 100_000, (new PriceCalculator)->userPrice($units * 80_000));
    }

    public function test_it_rejects_a_price_that_would_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PriceCalculator)->userPrice(PHP_INT_MAX);
    }

    public function test_it_rejects_a_provider_cost_product_that_would_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PriceCalculator)->providerCost(new TokenUsage(PHP_INT_MAX, 0, 0, 0), Rates::fromArray(self::rates()));
    }

    public function test_it_rejects_a_provider_cost_sum_that_would_overflow(): void
    {
        $rates = Rates::fromArray(array_fill_keys(array_keys(self::rates()), '0.001'));
        $this->expectException(InvalidArgumentException::class);

        (new PriceCalculator)->providerCost(new TokenUsage(PHP_INT_MAX, 1, 0, 0), $rates);
    }

    public function test_it_rejects_reference_image_token_overflow(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PriceCalculator)->estimate('', '1024x1024', 'low', PHP_INT_MAX, self::table());
    }
}
