<?php

declare(strict_types=1);

namespace App\Pricing;

use InvalidArgumentException;

final readonly class TokenUsage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {
        if (min($inputTextTokens, $inputImageTokens, $outputTokens, $reasoningTokens) < 0) {
            throw new InvalidArgumentException('Token counts must be non-negative integers.');
        }
    }

    /** @param array<string, mixed> $usage */
    public static function fromArray(array $usage): self
    {
        $counts = [];

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            $count = $usage[$key] ?? null;

            if (! is_int($count) || $count < 0) {
                throw new InvalidArgumentException("The {$key} count must be a non-negative integer.");
            }

            $counts[$key] = $count;
        }

        return new self(
            $counts['input_text_tokens'],
            $counts['input_image_tokens'],
            $counts['output_tokens'],
            $counts['reasoning_tokens'],
        );
    }
}
