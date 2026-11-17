<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Support\DecimalString;
use InvalidArgumentException;

final readonly class Rates
{
    /** @param array<string, string> $original */
    private function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        private array $original,
    ) {}

    /** @param array<string, mixed> $rates */
    public static function fromArray(array $rates): self
    {
        $original = [];
        $converted = [];

        foreach (['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
            $rate = $rates[$key] ?? null;

            if (! is_string($rate)) {
                throw new InvalidRate("The {$key} rate must be a decimal string.");
            }

            try {
                $converted[$key] = DecimalString::toScaledInt($rate, 3);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidRate("The {$key} rate must be an integer number of nanodollars per token.", 0, $exception);
            }

            $original[$key] = $rate;
        }

        return new self(
            $converted['input_text_tokens'],
            $converted['input_image_tokens'],
            $converted['output_tokens'],
            $converted['reasoning_tokens'],
            $original,
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return $this->original;
    }
}
