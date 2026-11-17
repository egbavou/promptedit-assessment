<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pricing\TokenUsage;
use App\Support\DecimalString;
use InvalidArgumentException;

final readonly class UsageDay
{
    private function __construct(
        // The report total has no date in the API response.
        public ?string $date,
        public int $generations,
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public int $costNanodollars,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (array_key_exists('date', $data)) {
            self::validateDate($data['date'], 'date');
        }

        if (! is_int($data['generations'] ?? null) || $data['generations'] < 0) {
            throw new InvalidPixelforgePayload('The generations field must be a non-negative integer.');
        }

        if (! is_string($data['cost_usd'] ?? null) || preg_match('/\A[0-9]+\.[0-9]{9}\z/', $data['cost_usd']) !== 1) {
            throw new InvalidPixelforgePayload('The cost_usd field must be an unsigned decimal string with nine decimal places.');
        }

        try {
            $usage = TokenUsage::fromArray($data);
            $cost = DecimalString::toScaledInt($data['cost_usd'], 9);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidPixelforgePayload('Invalid usage: '.$exception->getMessage(), 0, $exception);
        }

        return new self(
            date: $data['date'] ?? null,
            generations: $data['generations'],
            inputTextTokens: $usage->inputTextTokens,
            inputImageTokens: $usage->inputImageTokens,
            outputTokens: $usage->outputTokens,
            reasoningTokens: $usage->reasoningTokens,
            costNanodollars: $cost,
        );
    }

    public static function validateDate(mixed $value, string $field): void
    {
        if (! is_string($value) || preg_match('/\A([0-9]{4})-([0-9]{2})-([0-9]{2})\z/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new InvalidPixelforgePayload("The {$field} field must be a valid YYYY-MM-DD date.");
        }
    }
}
