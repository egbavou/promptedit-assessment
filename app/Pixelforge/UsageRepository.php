<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final readonly class UsageRepository
{
    public function __construct(private PixelforgeClient $client) {}

    public function forRange(string $startDate, string $endDate): UsageReport
    {
        // Usage belongs to an API key, so never reuse another environment's cache.
        $scope = hash('sha256', config('services.pixelforge.base_url').'|'.config('services.pixelforge.key'));
        $key = "pixelforge.usage.{$scope}.{$startDate}.{$endDate}";
        /** @var array<string, mixed>|null $payload */
        $payload = Cache::get($key);
        $cached = $payload !== null;
        $payload ??= $this->client->usagePayload($startDate, $endDate);
        $report = UsageReport::fromArray($payload);

        $expected = [];
        for ($date = CarbonImmutable::parse($startDate, 'UTC'); $date->toDateString() <= $endDate; $date = $date->addDay()) {
            $expected[] = $date->toDateString();
        }
        $dates = array_map(fn (UsageDay $day) => $day->date, $report->days);
        sort($dates);
        if ($report->startDate !== $startDate || $report->endDate !== $endDate || $dates !== $expected) {
            throw new InvalidPixelforgePayload('Pixelforge usage must contain exactly one row per requested UTC day.');
        }

        if (! $cached) {
            // Cache decoded JSON only; the configured cache rejects serialized objects.
            Cache::put($key, $payload, 300);
        }

        return $report;
    }
}
