<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\InvalidPixelforgePayload;

final readonly class UsageReport
{
    /** @param list<UsageDay> $days */
    private function __construct(
        public string $startDate,
        public string $endDate,
        public array $days,
        public UsageDay $total,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['object'] ?? null) !== 'usage') {
            throw new InvalidPixelforgePayload('The object field must be usage.');
        }
        UsageDay::validateDate($data['start_date'] ?? null, 'start_date');
        UsageDay::validateDate($data['end_date'] ?? null, 'end_date');
        if (! is_array($data['data'] ?? null) || ! array_is_list($data['data']) || ! is_array($data['total'] ?? null)) {
            throw new InvalidPixelforgePayload('Usage must contain a data list and a total object.');
        }

        $days = [];

        foreach ($data['data'] as $day) {
            if (! is_array($day) || ! array_key_exists('date', $day)) {
                throw new InvalidPixelforgePayload('Each usage day must be an object with a date.');
            }
            $days[] = UsageDay::fromArray($day);
        }

        return new self(
            startDate: $data['start_date'],
            endDate: $data['end_date'],
            days: $days,
            total: UsageDay::fromArray($data['total']),
        );
    }
}
