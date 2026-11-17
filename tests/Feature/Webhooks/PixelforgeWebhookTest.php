<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Billing\Ledger;
use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Jobs\StoreGeneratedImage;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Generations\PipelineTestCase;

class PixelforgeWebhookTest extends PipelineTestCase
{
    private const string SECRET = 'whsec_feature_test';

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        config()->set('services.pixelforge.webhook_secret', self::SECRET);
    }

    #[DataProvider('initialStatuses')]
    public function test_succeeded_settles_exactly_and_records_the_event(string $status): void
    {
        $generation = $this->heldGeneration(['status' => $status, 'estimated_max_nanodollars' => 500_000_000]);
        $event = $this->event($generation);

        $this->deliver($event)->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame($event['data']['object']['id'], $generation->pixelforge_id);
        $this->assertSame(305_510_000, $generation->provider_cost_nanodollars);
        $this->assertSame(381_900_000, $generation->price_nanodollars);
        $this->assertEquals($event['data']['object']['usage'], $generation->usage);
        $this->assertSame($event['data']['object']['image_url'], $generation->image_url);
        $this->assertSame(1, $generation->ledgerEntries()->where('type', LedgerEntryType::Charge)->count());
        $this->assertSame(-381_900_000, $generation->ledgerEntries()->where('type', LedgerEntryType::Charge)->sole()->amount_nanodollars);
        $this->assertSame(1, $generation->ledgerEntries()->where('type', LedgerEntryType::HoldRelease)->count());
        $this->assertSame(4_618_100_000, app(Ledger::class)->available($generation->user));
        $this->assertSame(0, app(Ledger::class)->reserved($generation->user));
        $stored = WebhookEvent::query()->sole();
        $this->assertSame($event['id'], $stored->id);
        $this->assertSame($event['type'], $stored->type);
        $this->assertSame($generation->pixelforge_id, $stored->pixelforge_generation_id);
        $this->assertEquals($event, $stored->payload);
        $this->assertTrue($stored->received_at->equalTo(now()));
        Queue::assertPushed(StoreGeneratedImage::class, fn (StoreGeneratedImage $job): bool => $job->generationId === $generation->id && $job->afterCommit === true);
        Queue::assertPushed(StoreGeneratedImage::class, 1);
        Http::assertNothingSent();
    }

    public static function initialStatuses(): array
    {
        return [['pending'], ['queued']];
    }

    #[DataProvider('duplicateIds')]
    public function test_repeated_success_objects_charge_once_whether_the_event_id_is_reused(bool $sameId): void
    {
        $generation = $this->heldGeneration(['estimated_max_nanodollars' => 500_000_000]);
        $event = $this->event($generation);
        $this->deliver($event)->assertNoContent();
        $before = $generation->refresh()->getRawOriginal();
        if (! $sameId) {
            $event['id'] = 'evt_second';
        }

        $this->deliver($event)->assertNoContent();

        $this->assertSame($before, $generation->refresh()->getRawOriginal());
        $this->assertDatabaseCount('webhook_events', $sameId ? 1 : 2);
        $this->assertSame(1, $generation->ledgerEntries()->where('type', LedgerEntryType::Charge)->count());
        $this->assertSame(1, $generation->ledgerEntries()->where('type', LedgerEntryType::HoldRelease)->count());
        Queue::assertPushed(StoreGeneratedImage::class, 1);
        Queue::assertPushed(SyncGeneration::class, 1);
        Http::assertNothingSent();
    }

    public static function duplicateIds(): array
    {
        return [[true], [false]];
    }

    public function test_a_duplicate_event_is_ignored_before_parsing_its_generation(): void
    {
        $generation = $this->heldGeneration();
        $event = $this->event($generation, 'processing');
        $this->deliver($event)->assertNoContent();
        $before = $generation->refresh()->getRawOriginal();
        $event['data']['object'] = ['id' => $generation->pixelforge_id];

        $this->deliver($event)->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame($before, $generation->refresh()->getRawOriginal());
        Queue::assertPushed(SyncGeneration::class, 1);
    }

    public function test_processing_after_success_does_not_change_any_generation_or_ledger_fields(): void
    {
        $generation = $this->heldGeneration(['estimated_max_nanodollars' => 500_000_000]);
        $this->deliver($this->event($generation))->assertNoContent();
        $before = $generation->refresh()->getRawOriginal();
        $ledger = $generation->ledgerEntries()->orderBy('id')->get()->toArray();
        $this->travel(10)->seconds();

        $this->deliver($this->event($generation, 'processing', 'evt_late_processing'))->assertNoContent();

        $this->assertSame($before, $generation->refresh()->getRawOriginal());
        $this->assertSame($ledger, $generation->ledgerEntries()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('webhook_events', 2);
        Queue::assertPushed(StoreGeneratedImage::class, 1);
        Queue::assertPushed(SyncGeneration::class, 1);
    }

    public function test_processing_before_the_post_response_finds_pending_generation_by_metadata_and_starts_sync(): void
    {
        $generation = $this->heldGeneration();
        $this->assertNull($generation->pixelforge_id);
        $event = $this->event($generation, 'processing');

        $this->deliver($event)->assertNoContent();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertSame($event['data']['object']['id'], $generation->pixelforge_id);
        $this->assertTrue($generation->accepted_at->equalTo(now()));
        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $job): bool => $job->generationId === $generation->id
            && $job->sequence === 0 && $job->delay->equalTo(now()->addMinutes(2)) && $job->afterCommit === true);
        Queue::assertPushed(SyncGeneration::class, 1);
        Queue::assertNotPushed(StoreGeneratedImage::class);
        $this->assertSame(1, $generation->ledgerEntries()->count());
        Http::assertNothingSent();
    }

    public function test_failed_releases_the_hold_and_stores_a_user_message_without_a_charge(): void
    {
        $generation = $this->heldGeneration();

        $this->deliver($this->event($generation, 'failed'))->assertNoContent();

        $this->assertFailedWithoutCharge($generation, FailureReason::InternalError->value, FailureReason::InternalError->message());
        $this->assertDatabaseCount('webhook_events', 1);
        Queue::assertNotPushed(StoreGeneratedImage::class);
        Http::assertNothingSent();
    }

    #[DataProvider('invalidDeliveries')]
    public function test_invalid_signatures_do_not_store_events_or_change_generations(string $failure): void
    {
        $generation = $this->heldGeneration();
        $before = $generation->refresh()->getRawOriginal();
        $body = json_encode($this->event($generation), JSON_THROW_ON_ERROR);
        $timestamp = now()->timestamp - ($failure === 'old' ? 301 : 0);
        $signature = $this->signature($body, $timestamp);
        if ($failure === 'wrong') {
            $signature = 't='.$timestamp.',v1='.str_repeat('0', 64);
        } elseif ($failure === 'missing') {
            $signature = null;
        } elseif ($failure === 'changed') {
            $body .= "\n";
        }

        $this->sendRaw($body, $signature)->assertStatus(400);

        $this->assertSame($before, $generation->refresh()->getRawOriginal());
        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame(1, $generation->ledgerEntries()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function invalidDeliveries(): array
    {
        return [['wrong'], ['missing'], ['old'], ['changed']];
    }

    #[DataProvider('missingSecrets')]
    public function test_an_unconfigured_secret_logs_a_critical_error_and_returns_500(?string $secret): void
    {
        config()->set('services.pixelforge.webhook_secret', $secret);
        Log::spy();
        $body = '{"id":"evt_ping","type":"ping"}';

        $this->sendRaw($body, $this->signature($body))->assertStatus(500);

        Log::shouldHaveReceived('critical')->once();
        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public static function missingSecrets(): array
    {
        return [[null], ['']];
    }

    #[DataProvider('malformedEvents')]
    public function test_signed_malformed_json_or_event_envelopes_return_400(string $body): void
    {
        $this->sendRaw($body, $this->signature($body))->assertStatus(400);

        $this->assertDatabaseCount('webhook_events', 0);
        Queue::assertNothingPushed();
    }

    public static function malformedEvents(): array
    {
        return [
            ['{'], ['null'], ['[]'], ['"event"'], ['42'], ['true'],
            ['{}'], ['{"id":"evt_test"}'], ['{"type":"ping"}'],
            ['{"id":42,"type":"ping"}'], ['{"id":"evt_test","type":null}'],
            ['[{"id":"evt_test","type":"ping"}]'],
        ];
    }

    public function test_a_ledger_exception_rolls_back_the_event_generation_and_release_then_redelivery_succeeds(): void
    {
        $generation = $this->heldGeneration(['estimated_max_nanodollars' => 500_000_000]);
        $before = $generation->refresh()->getRawOriginal();
        $event = $this->event($generation);
        $this->partialMock(Ledger::class)->shouldReceive('charge')->once()->andThrow(new RuntimeException('Charge failed.'));
        Log::spy();

        $this->deliver($event)->assertStatus(500);

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame($before, $generation->refresh()->getRawOriginal());
        $this->assertSame(1, $generation->ledgerEntries()->count());
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('error')->once();

        $this->app->forgetInstance(Ledger::class);
        $this->deliver($event)->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
        $this->assertSame(1, $generation->ledgerEntries()->where('type', LedgerEntryType::Charge)->count());
        $this->assertSame(1, $generation->ledgerEntries()->where('type', LedgerEntryType::HoldRelease)->count());
        Queue::assertPushed(StoreGeneratedImage::class, 1);
    }

    public function test_invalid_generation_data_rolls_back_the_event_and_returns_500(): void
    {
        $generation = $this->heldGeneration();
        $before = $generation->refresh()->getRawOriginal();
        $event = $this->event($generation);
        $event['data']['object']['usage'] = null;
        Log::spy();

        $this->deliver($event)->assertStatus(500);

        $this->assertDatabaseCount('webhook_events', 0);
        $this->assertSame($before, $generation->refresh()->getRawOriginal());
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('error')->once();
    }

    #[DataProvider('ignoredEventTypes')]
    public function test_ping_and_unknown_types_are_not_stored(string $type): void
    {
        Log::spy();
        $event = ['id' => 'evt_ignored', 'object' => 'event', 'type' => $type, 'created_at' => now()->toIso8601ZuluString()];

        $this->deliver($event)->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 0);
        if ($type !== 'ping') {
            Log::shouldHaveReceived('info')->once();
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function ignoredEventTypes(): array
    {
        return [['ping'], ['generation.future_event']];
    }

    public function test_an_unknown_generation_is_logged_and_the_event_is_kept(): void
    {
        $generation = Generation::factory()->make(['id' => '00000000-0000-4000-8000-000000000001']);
        Log::spy();

        $this->deliver($this->event($generation))->assertNoContent();

        $this->assertDatabaseCount('webhook_events', 1);
        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        Log::shouldHaveReceived('warning')->once();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_jobs_are_only_enqueued_after_the_outer_transaction_commits(): void
    {
        $generation = $this->heldGeneration(['estimated_max_nanodollars' => 500_000_000]);
        Queue::swap($this->originalQueueManager);
        config()->set('queue.default', 'database');

        DB::transaction(function () use ($generation): void {
            $this->deliver($this->event($generation))->assertNoContent();
            $this->assertDatabaseCount('webhook_events', 1);
            $this->assertDatabaseCount('jobs', 0);
        });

        $this->assertDatabaseCount('jobs', 2);
        $names = DB::table('jobs')->pluck('payload')->map(fn (string $payload): string => json_decode($payload, true, flags: JSON_THROW_ON_ERROR)['displayName'])->all();
        $this->assertContains(StoreGeneratedImage::class, $names);
        $this->assertContains(SyncGeneration::class, $names);
        Http::assertNothingSent();
    }

    public function test_the_route_has_no_web_or_throttle_middleware_and_sets_no_cookies_without_csrf_or_authentication(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.pixelforge');
        $this->assertNotNull($route);
        $this->assertSame([], $route->gatherMiddleware());
        $body = '{"id":"evt_ping","type":"ping"}';

        $response = $this->sendRaw($body, $this->signature($body));

        $response->assertNoContent();
        $this->assertSame([], $response->headers->getCookies());
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    private function event(Generation $generation, string $status = 'succeeded', string $id = 'evt_test'): array
    {
        return [
            'id' => $id,
            'object' => 'event',
            'type' => 'generation.'.$status,
            'created_at' => now()->toIso8601ZuluString(),
            'data' => ['object' => $this->snapshot($generation, $status)],
        ];
    }

    private function deliver(array $event): TestResponse
    {
        $body = json_encode($event, JSON_THROW_ON_ERROR);

        return $this->sendRaw($body, $this->signature($body));
    }

    private function signature(string $body, ?int $timestamp = null): string
    {
        $timestamp ??= now()->timestamp;

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, self::SECRET);
    }

    private function sendRaw(string $body, ?string $signature): TestResponse
    {
        $headers = ['CONTENT_TYPE' => 'application/json'];
        if ($signature !== null) {
            $headers['HTTP_PIXELFORGE_SIGNATURE'] = $signature;
        }

        return $this->call('POST', '/webhooks/pixelforge', [], [], [], $headers, $body);
    }
}
