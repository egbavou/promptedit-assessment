<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pixelforge\Exceptions\PixelforgeRequestFailed;
use App\Pixelforge\Exceptions\PixelforgeUnreachable;
use App\Pixelforge\Exceptions\PricingUnavailable;
use App\Pricing\PricingTable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final readonly class PricingRepository
{
    public function __construct(private PixelforgeClient $client) {}

    public function current(): PricingTable
    {
        /** @var array<string, mixed>|null $payload */
        $payload = Cache::get('pixelforge.pricing');

        if ($payload !== null) {
            return PricingTable::fromApi($payload);
        }

        try {
            $payload = $this->client->pricingPayload();
            $pricing = PricingTable::fromApi($payload);
        } catch (PixelforgeUnreachable|PixelforgeRequestFailed|InvalidPixelforgePayload|InvalidArgumentException $exception) {
            /** @var array<string, mixed>|null $lastKnown */
            $lastKnown = Cache::get('pixelforge.pricing.last_known');

            if ($lastKnown === null) {
                throw new PricingUnavailable($exception);
            }

            Log::warning('Could not refresh Pixelforge pricing; using the last known payload.', ['exception' => $exception]);

            $pricing = PricingTable::fromApi($lastKnown);
            Cache::put('pixelforge.pricing', $lastKnown, now()->addMinute());

            return $pricing;
        }

        Cache::put('pixelforge.pricing', $payload, now()->addMinutes(10));
        Cache::forever('pixelforge.pricing.last_known', $payload);

        return $pricing;
    }
}
