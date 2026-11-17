<?php

declare(strict_types=1);

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pixelforge\UsageRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class UsageRepositoryTest extends PixelforgeTestCase
{
    public function test_only_raw_json_arrays_are_cached(): void
    {
        $payload = self::fixture('usage');
        Http::fake([self::BASE_URL.'/usage*' => Http::response($payload)]);
        Cache::shouldReceive('get')->once()->andReturnNull();
        Cache::shouldReceive('put')->once()->withArgs(fn (string $key, mixed $value, mixed $ttl) => $value === $payload && $ttl === 300);

        $report = app(UsageRepository::class)->forRange('2026-11-01', '2026-11-02');

        $this->assertSame(169_080_000, $report->days[0]->costNanodollars);
    }

    #[DataProvider('invalidCoverage')]
    public function test_invalid_coverage_is_rejected_without_caching(string $change): void
    {
        $payload = self::fixture('usage');
        if ($change === 'duplicate') {
            $payload['data'][1] = $payload['data'][0];
        } elseif ($change === 'missing') {
            array_pop($payload['data']);
        } else {
            $payload['start_date'] = '2026-10-31';
        }
        Http::fake([self::BASE_URL.'/usage*' => Http::response($payload)]);
        Cache::spy();
        try {
            app(UsageRepository::class)->forRange('2026-11-01', '2026-11-02');
            $this->fail('Invalid coverage was accepted.');
        } catch (InvalidPixelforgePayload) {
            Cache::shouldNotHaveReceived('put');
        }
    }

    public static function invalidCoverage(): array
    {
        return [['duplicate'], ['missing'], ['range']];
    }

    public function test_usage_cache_is_isolated_per_api_key(): void
    {
        Http::fake([self::BASE_URL.'/usage*' => Http::response(self::fixture('usage'))]);
        $repository = app(UsageRepository::class);
        $repository->forRange('2026-11-01', '2026-11-02');
        config()->set('services.pixelforge.key', 'another-test-key');
        $repository->forRange('2026-11-01', '2026-11-02');

        Http::assertSentCount(2);
    }
}
