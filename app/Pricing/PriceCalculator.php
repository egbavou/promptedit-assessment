<?php

declare(strict_types=1);

namespace App\Pricing;

use InvalidArgumentException;

final class PriceCalculator
{
    public function providerCost(TokenUsage $usage, Rates $rates): int
    {
        $cost = 0;

        foreach (['inputTextTokens', 'inputImageTokens', 'outputTokens', 'reasoningTokens'] as $type) {
            $cost = self::add($cost, self::multiply($usage->{$type}, $rates->{$type}));
        }

        return $cost;
    }

    public function userPrice(int $providerCost): int
    {
        if ($providerCost < 0) {
            throw new InvalidArgumentException('Provider cost must be non-negative.');
        }

        // ceil(cost * 5 / 400_000) = ceil(cost / 80_000), without an overflowing intermediate product.
        $units = intdiv($providerCost, 80_000) + ($providerCost % 80_000 === 0 ? 0 : 1);

        return self::multiply($units, 100_000);
    }

    public function estimate(string $prompt, string $size, string $quality, int $referenceImageCount, PricingTable $table): Estimate
    {
        if ($referenceImageCount < 0) {
            throw new InvalidArgumentException('The reference image count must be non-negative.');
        }

        $text = intdiv(self::add(mb_strlen($prompt, 'UTF-8'), 3), 4);
        $minText = intdiv(self::multiply($text, 85), 100);
        $maxText = intdiv(self::add(self::multiply($text, 115), 99), 100);
        $images = self::multiply($referenceImageCount, $table->tokensPerReferenceImage);
        $output = $table->outputTokens($quality, $size);

        $minTokens = new TokenUsage($minText, $images, $output, $table->minReasoningTokens($quality));
        $maxTokens = new TokenUsage($maxText, $images, $output, $table->maxReasoningTokens($quality));
        $minCost = $this->providerCost($minTokens, $table->rates);
        $maxCost = $this->providerCost($maxTokens, $table->rates);

        return new Estimate(
            $minCost,
            $maxCost,
            $this->userPrice($minCost),
            $this->userPrice($maxCost),
            $minTokens,
            $maxTokens,
        );
    }

    private static function multiply(int $left, int $right): int
    {
        if ($right !== 0 && $left > intdiv(PHP_INT_MAX, $right)) {
            throw new InvalidArgumentException('The calculation exceeds the integer range.');
        }

        return $left * $right;
    }

    private static function add(int $left, int $right): int
    {
        if ($left > PHP_INT_MAX - $right) {
            throw new InvalidArgumentException('The calculation exceeds the integer range.');
        }

        return $left + $right;
    }
}
