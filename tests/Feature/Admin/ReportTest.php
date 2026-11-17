<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Unit\Pixelforge\PixelforgeTestCase;

class ReportTest extends PixelforgeTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-17T12:00:00Z'));
    }

    public function test_guests_must_log_in_and_non_admins_are_forbidden(): void
    {
        $this->get('/admin/report')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get('/admin/report')->assertForbidden();
        $this->actingAs(User::factory()->unverified()->create())->get('/admin/report')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_an_unverified_admin_can_open_the_report(): void
    {
        Http::fake([self::BASE_URL.'/usage*' => Http::response(self::fixture('usage'))]);

        $this->actingAs(User::factory()->admin()->unverified()->create())
            ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Report'));
    }

    public function test_admin_sees_the_report_and_correct_usage_query_is_cached_for_five_minutes(): void
    {
        Http::fake([self::BASE_URL.'/usage*' => Http::response(self::fixture('usage'))]);
        $this->actingAs(User::factory()->admin()->create());
        for ($i = 0; $i < 2; $i++) {
            $this->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']))
                ->assertOk()->assertInertia(fn (Assert $page) => $page->component('Admin/Report')
                ->where('auth.user.is_admin', true)->where('filters.start', '2026-11-01')->where('filters.end', '2026-11-02')
                ->has('report.days', 2)->where('report.days.0.pixelforge.match', false)->where('report.days.1.pixelforge.match', true));
        }
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === self::BASE_URL.'/usage?start_date=2026-11-01&end_date=2026-11-02');
        $this->travel(301)->seconds();
        $this->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_defaults_are_the_last_thirty_days_ending_today_in_utc(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-18T00:30:00+01:00'));
        Http::fake([self::BASE_URL.'/usage*' => Http::response($this->rangePayload('2026-10-19', '2026-11-17'))]);
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.report'))
            ->assertOk()->assertInertia(fn (Assert $page) => $page->where('filters.start', '2026-10-19')
            ->where('filters.end', '2026-11-17')->has('report.days', 30)->where('report.pixelforge.matching_days', 30));
    }

    public function test_ninety_two_inclusive_days_are_allowed_and_ranges_have_separate_cache_entries(): void
    {
        Http::fake(fn (Request $request) => Http::response($this->rangePayload($request['start_date'], $request['end_date'])));
        $this->actingAs(User::factory()->admin()->create());
        $this->get(route('admin.report', ['start' => '2026-08-18', 'end' => '2026-11-17']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('report.days', 92));
        $this->get(route('admin.report', ['start' => '2026-11-17', 'end' => '2026-11-17']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('report.days', 1));
        Http::assertSentCount(2);
    }

    #[DataProvider('invalidRanges')]
    public function test_invalid_ranges_are_rejected_before_calling_pixelforge(array $query, string $field): void
    {
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.report', $query))->assertSessionHasErrors($field);
        Http::assertNothingSent();
    }

    public static function invalidRanges(): array
    {
        return [
            [['start' => '2026-08-17', 'end' => '2026-11-17'], 'end'],
            [['start' => '2026-11-03', 'end' => '2026-11-02'], 'end'],
            [['start' => '2026-11-01', 'end' => '2026-11-18'], 'end'],
            [['start' => '2026-02-30', 'end' => '2026-11-17'], 'start'],
            [['start' => '2026-11-1', 'end' => '2026-11-17'], 'start'],
            [['start' => 'tomorrow', 'end' => 'bad'], 'end'],
            [['start' => ['2026-11-01'], 'end' => '2026-11-17'], 'start'],
            [['start' => '', 'end' => '2026-11-17'], 'start'],
            [['start' => '0000-01-01', 'end' => '0000-01-02'], 'start'],
        ];
    }

    #[DataProvider('unavailableResponses')]
    public function test_provider_outages_and_malformed_usage_preserve_our_report(string $failure): void
    {
        Log::spy();
        Http::fake([self::BASE_URL.'/usage*' => match ($failure) {
            'connection' => Http::failedConnection(),
            'server' => Http::response(self::fixture('error-overloaded'), 500),
            'malformed' => Http::response([...self::fixture('usage'), 'data' => null]),
            'incomplete' => Http::response([...self::fixture('usage'), 'data' => []]),
        }]);
        Generation::factory()->succeeded()->create([
            'completed_at' => '2026-11-01T10:00:00Z', 'price_nanodollars' => 74_500_000, 'provider_cost_nanodollars' => 59_550_000,
        ]);
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('report.pixelforge', null)
                ->where('report.days.0.pixelforge', null)->where('report.totals.billed', '$0.0745')
                ->where('report.message', 'Pixelforge usage is unavailable right now; showing our records only.'));
        Log::shouldHaveReceived('warning')->once();
    }

    public static function unavailableResponses(): array
    {
        return [['connection'], ['server'], ['malformed'], ['incomplete']];
    }

    public function test_admin_flag_is_shared_as_a_boolean_for_sidebar_visibility(): void
    {
        foreach ([false, true] as $admin) {
            $user = User::factory()->create(['is_admin' => $admin]);
            $this->actingAs($user)->get(route('ledger.index'))->assertInertia(fn (Assert $page) => $page->where('auth.user.is_admin', $admin));
        }
    }

    private function rangePayload(string $start, string $end): array
    {
        $payload = self::fixture('usage');
        $zero = $payload['data'][1];
        $payload['start_date'] = $start;
        $payload['end_date'] = $end;
        $payload['data'] = [];
        for ($day = CarbonImmutable::parse($start, 'UTC'); $day->toDateString() <= $end; $day = $day->addDay()) {
            $payload['data'][] = [...$zero, 'date' => $day->toDateString()];
        }
        $payload['total'] = $zero;
        unset($payload['total']['date']);

        return $payload;
    }
}
