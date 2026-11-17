<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Models\Generation;
use App\Models\User;
use App\Reports\ProfitReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Unit\Pixelforge\PixelforgeTestCase;

class ProfitReportTest extends PixelforgeTestCase
{
    use RefreshDatabase;

    public function test_exact_daily_user_and_total_amounts_use_utc_completion_days(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'Pacific/Honolulu'");
        $alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.test']);
        $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.test']);
        User::factory()->create(['name' => 'No usage']);
        Generation::factory()->for($alice)->succeeded()->create([
            'completed_at' => CarbonImmutable::parse('2026-11-02T00:30:00+01:00'),
            'provider_cost_nanodollars' => 100_000_001, 'price_nanodollars' => 125_100_000,
        ]);
        Generation::factory()->for($bob)->succeeded()->create([
            'completed_at' => '2026-11-01T12:00:00Z',
            'provider_cost_nanodollars' => 200_000_002, 'price_nanodollars' => 250_100_000,
        ]);
        Generation::factory()->for($alice)->succeeded()->create([
            'created_at' => '2026-11-01T23:59:00Z', 'completed_at' => '2026-11-02T00:01:00Z',
            'provider_cost_nanodollars' => 59_550_000, 'price_nanodollars' => 74_500_000,
        ]);
        foreach (['pending', 'queued', 'processing', 'failed'] as $status) {
            Generation::factory()->for($alice)->create([
                'status' => $status, 'completed_at' => '2026-11-02T10:00:00Z',
                'provider_cost_nanodollars' => 999_000_000, 'price_nanodollars' => 999_000_000,
            ]);
        }
        foreach (['2026-10-31T23:59:59Z', '2026-11-04T00:00:00Z'] as $outside) {
            Generation::factory()->for($bob)->succeeded()->create([
                'completed_at' => $outside, 'provider_cost_nanodollars' => 999_000_000, 'price_nanodollars' => 999_000_000,
            ]);
        }
        $payload = $this->payload();
        $payload['data'][0]['generations'] = 2;
        $payload['data'][0]['cost_usd'] = '0.300000003';
        $payload['data'][1]['generations'] = 1;
        $payload['data'][1]['cost_usd'] = '0.059550000';
        Http::fake([self::BASE_URL.'/usage*' => Http::response($payload)]);

        $report = $this->build();

        $this->assertSame([
            ['date' => '2026-11-01', ...$this->amounts(2, 375_200_000, 300_000_003, 75_199_997, '$0.3752', '$0.300000003', '$0.075199997'), 'pixelforge' => $this->provider(2, 300_000_003, '$0.300000003', true, 0, '$0.000000000')],
            ['date' => '2026-11-02', ...$this->amounts(1, 74_500_000, 59_550_000, 14_950_000, '$0.0745', '$0.059550000', '$0.014950000'), 'pixelforge' => $this->provider(1, 59_550_000, '$0.059550000', true, 0, '$0.000000000')],
            ['date' => '2026-11-03', ...$this->amounts(0, 0, 0, 0, '$0.0000', '$0.000000000', '$0.000000000'), 'pixelforge' => $this->provider(0, 0, '$0.000000000', true, 0, '$0.000000000')],
        ], $report['days']);
        $this->assertSame([
            ['id' => $alice->id, 'name' => 'Alice', 'email' => 'alice@example.test', ...$this->amounts(2, 199_600_000, 159_550_001, 40_049_999, '$0.1996', '$0.159550001', '$0.040049999')],
            ['id' => $bob->id, 'name' => 'Bob', 'email' => 'bob@example.test', ...$this->amounts(1, 250_100_000, 200_000_002, 50_099_998, '$0.2501', '$0.200000002', '$0.050099998')],
        ], $report['users']);
        $this->assertSame($this->amounts(3, 449_700_000, 359_550_003, 90_149_997, '$0.4497', '$0.359550003', '$0.090149997'), $report['totals']);
        $this->assertSame(3, $report['pixelforge']['matching_days']);
        $this->assertSame(3, $report['pixelforge']['total_days']);
        $this->assertNull($report['message']);
    }

    public function test_a_single_nanodollar_or_a_generation_count_difference_fails_reconciliation(): void
    {
        Generation::factory()->succeeded()->create([
            'completed_at' => '2026-11-01T10:00:00Z',
            'provider_cost_nanodollars' => 169_079_999, 'price_nanodollars' => 211_400_000,
        ]);
        $payload = $this->payload();
        $payload['data'][0]['generations'] = 1;
        $payload['data'][1]['generations'] = 1;
        Http::fake([self::BASE_URL.'/usage*' => Http::response($payload)]);

        $report = $this->build();

        $this->assertSame($this->provider(1, 169_080_000, '$0.169080000', false, -1, '-$0.000000001'), $report['days'][0]['pixelforge']);
        $this->assertSame($this->provider(1, 0, '$0.000000000', false, 0, '$0.000000000'), $report['days'][1]['pixelforge']);
        $this->assertSame(1, $report['pixelforge']['matching_days']);
    }

    private function build(): array
    {
        return app(ProfitReport::class)->build(CarbonImmutable::parse('2026-11-01', 'UTC'), CarbonImmutable::parse('2026-11-03', 'UTC'));
    }

    private function payload(): array
    {
        $payload = self::fixture('usage');
        $payload['end_date'] = '2026-11-03';
        $payload['data'][] = [...$payload['data'][1], 'date' => '2026-11-03'];

        return $payload;
    }

    private function amounts(int $generations, int $billed, int $cost, int $margin, string $billedDisplay, string $costDisplay, string $marginDisplay): array
    {
        return ['generations' => $generations, 'billed_nanodollars' => $billed, 'cost_nanodollars' => $cost, 'margin_nanodollars' => $margin, 'billed' => $billedDisplay, 'cost' => $costDisplay, 'margin' => $marginDisplay];
    }

    private function provider(int $generations, int $cost, string $display, bool $match, int $difference, string $differenceDisplay): array
    {
        return ['generations' => $generations, 'cost_nanodollars' => $cost, 'cost' => $display, 'match' => $match, 'difference_nanodollars' => $difference, 'difference' => $differenceDisplay];
    }
}
