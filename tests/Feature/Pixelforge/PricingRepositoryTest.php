<?php

declare(strict_types=1);

namespace Tests\Feature\Pixelforge;

use App\Pixelforge\Exceptions\PricingUnavailable;
use App\Pixelforge\PricingRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\Pixelforge\PixelforgeTestCase;

class PricingRepositoryTest extends PixelforgeTestCase
{
    use RefreshDatabase;

    public function test_the_repository_is_a_singleton_and_caches_only_raw_payloads(): void
    {
        $payload = self::fixture('pricing');
        Http::fake([self::BASE_URL.'/pricing' => Http::response($payload)]);
        $repository = app(PricingRepository::class);

        $this->assertSame($repository, app(PricingRepository::class));
        $this->assertSame(1100, $repository->current()->outputTokens('medium', '1024x1024'));
        $this->travel(9)->minutes();
        $this->assertSame(765, $repository->current()->tokensPerReferenceImage);

        Http::assertSentCount(1);
        $this->assertSame($payload, Cache::get('pixelforge.pricing'));
        $this->assertSame($payload, Cache::get('pixelforge.pricing.last_known'));
    }

    public function test_it_refetches_after_ten_minutes_and_updates_both_payloads(): void
    {
        $updated = self::fixture('pricing');
        $updated['tokens']['per_reference_image'] = 800;
        Http::fake([self::BASE_URL.'/pricing' => Http::sequence()->push(self::fixture('pricing'))->push($updated)]);
        $repository = app(PricingRepository::class);

        $repository->current();
        $this->travel(11)->minutes();

        $this->assertSame(800, $repository->current()->tokensPerReferenceImage);
        $this->assertSame($updated, Cache::get('pixelforge.pricing'));
        $this->assertSame($updated, Cache::get('pixelforge.pricing.last_known'));
        Http::assertSentCount(2);
    }

    #[DataProvider('fetchFailures')]
    public function test_it_uses_last_known_payload_and_warns_when_refetch_fails(string $failure): void
    {
        $payload = self::fixture('pricing');
        Http::fake([self::BASE_URL.'/pricing' => Http::response($payload)]);
        app(PricingRepository::class)->current();
        $this->travel(11)->minutes();
        Log::spy();
        $this->fakeFailure($failure);

        $this->assertSame(1100, app(PricingRepository::class)->current()->outputTokens('medium', '1024x1024'));
        $this->assertSame($payload, Cache::get('pixelforge.pricing'));
        $this->assertSame($payload, Cache::get('pixelforge.pricing.last_known'));
        Log::shouldHaveReceived('warning')->once();
    }

    public static function fetchFailures(): array
    {
        return [['server'], ['connection'], ['invalid'], ['malformed'], ['scalar'], ['null']];
    }

    #[DataProvider('fetchFailures')]
    public function test_unavailability_without_a_last_known_payload_never_caches_invalid_data(string $failure): void
    {
        $this->fakeFailure($failure);

        try {
            app(PricingRepository::class)->current();
            $this->fail('Expected pricing to be unavailable.');
        } catch (PricingUnavailable $exception) {
            $this->assertNotNull($exception->getPrevious());
        }

        $this->assertNull(Cache::get('pixelforge.pricing'));
        $this->assertNull(Cache::get('pixelforge.pricing.last_known'));
    }

    public function test_the_last_known_payload_does_not_expire(): void
    {
        Http::fake([self::BASE_URL.'/pricing' => Http::response(self::fixture('pricing'))]);
        app(PricingRepository::class)->current();
        $this->travel(1)->year();
        $this->fakeFailure('server');

        $this->assertSame(765, app(PricingRepository::class)->current()->tokensPerReferenceImage);
    }

    public function test_payloads_round_trip_through_the_database_cache_without_serializable_classes(): void
    {
        config()->set('cache.default', 'database');
        Cache::forget('pixelforge.pricing');
        Cache::forget('pixelforge.pricing.last_known');
        $this->assertFalse(config('cache.serializable_classes'));
        Http::fake([self::BASE_URL.'/pricing' => Http::response(self::fixture('pricing'))]);

        $repository = app(PricingRepository::class);
        $repository->current();
        $this->assertSame(1100, $repository->current()->outputTokens('medium', '1024x1024'));
        $this->travel(11)->minutes();
        $this->fakeFailure('server');
        $this->assertSame(765, $repository->current()->tokensPerReferenceImage);
    }

    private function fakeFailure(string $failure): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();

        $response = match ($failure) {
            'server' => Http::response([], 500),
            'connection' => Http::failedConnection(),
            'invalid' => Http::response(['rates' => []]),
            'malformed' => Http::response('<html>Not JSON</html>'),
            'scalar' => Http::response('"not a pricing object"'),
            'null' => Http::response('null'),
        };

        Http::fake([self::BASE_URL.'/pricing' => $response]);
    }
}
