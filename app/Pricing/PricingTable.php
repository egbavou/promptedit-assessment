<?php

declare(strict_types=1);

namespace App\Pricing;

use InvalidArgumentException;

final readonly class PricingTable
{
    /**
     * @param  array<string, array<string, int>>  $output
     * @param  array<string, array{min: int, max: int}>  $reasoning
     */
    private function __construct(
        public Rates $rates,
        public int $tokensPerReferenceImage,
        private array $output,
        private array $reasoning,
    ) {}

    /** @param array<string, mixed> $json */
    public static function fromApi(array $json): self
    {
        $rates = $json['rates'] ?? null;
        $tokens = $json['tokens'] ?? null;

        if (! is_array($rates) || ! is_array($tokens)) {
            throw new InvalidArgumentException('The pricing payload must contain rates and tokens.');
        }

        $perReferenceImage = self::tokenCount($tokens['per_reference_image'] ?? null);
        $output = $tokens['output'] ?? null;
        $reasoning = $tokens['reasoning'] ?? null;

        if (! is_array($output) || ! is_array($reasoning)) {
            throw new InvalidArgumentException('The pricing payload must contain output and reasoning token tables.');
        }

        $outputCounts = [];
        $reasoningCounts = [];

        foreach (['low', 'medium', 'high'] as $quality) {
            $sizes = $output[$quality] ?? null;
            $bounds = $reasoning[$quality] ?? null;

            if (! is_array($sizes) || ! is_array($bounds)) {
                throw new InvalidArgumentException("Missing token tables for quality: {$quality}.");
            }

            foreach (['1024x1024', '1536x1024', '1024x1536'] as $size) {
                $outputCounts[$quality][$size] = self::tokenCount($sizes[$size] ?? null);
            }

            $min = self::tokenCount($bounds['min'] ?? null);
            $max = self::tokenCount($bounds['max'] ?? null);

            if ($min > $max) {
                throw new InvalidArgumentException("The reasoning minimum exceeds the maximum for quality: {$quality}.");
            }

            $reasoningCounts[$quality] = ['min' => $min, 'max' => $max];
        }

        return new self(Rates::fromArray($rates), $perReferenceImage, $outputCounts, $reasoningCounts);
    }

    public function outputTokens(string $quality, string $size): int
    {
        return $this->output[$quality][$size]
            ?? throw new InvalidArgumentException("Unknown output quality or size: {$quality}, {$size}.");
    }

    public function minReasoningTokens(string $quality): int
    {
        return $this->reasoning[$quality]['min']
            ?? throw new InvalidArgumentException("Unknown reasoning quality: {$quality}.");
    }

    public function maxReasoningTokens(string $quality): int
    {
        return $this->reasoning[$quality]['max']
            ?? throw new InvalidArgumentException("Unknown reasoning quality: {$quality}.");
    }

    private static function tokenCount(mixed $value): int
    {
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Pricing token counts must be non-negative integers.');
        }

        return $value;
    }
}
