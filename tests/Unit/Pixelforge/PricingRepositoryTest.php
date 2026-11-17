<?php

declare(strict_types=1);

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\PricingRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PricingRepositoryTest extends PixelforgeTestCase
{
    public function test_fallback_is_cached_for_one_minute_before_retrying_the_provider(): void
    {
        $this->freezeSecond();
        $payload = self::fixture('pricing');
        Cache::forever('pixelforge.pricing.last_known', $payload);
        Http::fake([self::BASE_URL.'/pricing' => Http::response(self::fixture('error-overloaded'), 503)]);
        $repository = app(PricingRepository::class);

        $repository->current();
        $this->assertSame($payload, Cache::get('pixelforge.pricing'));
        $this->travel(59)->seconds();
        $repository->current();
        Http::assertSentCount(1);
        $this->travel(2)->seconds();
        $repository->current();
        Http::assertSentCount(2);
        $this->assertSame($payload, Cache::get('pixelforge.pricing.last_known'));
    }
}
