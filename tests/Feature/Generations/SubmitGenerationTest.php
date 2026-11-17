<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Jobs\StoreGeneratedImage;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class SubmitGenerationTest extends PipelineTestCase
{
    public function test_accepted_submission_records_the_snapshot_and_starts_sync_after_two_minutes(): void
    {
        $this->freezeSecond();
        $generation = $this->heldGeneration();
        $payload = $this->snapshot($generation);
        Http::fake([self::BASE_URL.'/generations' => Http::response($payload, 202)]);

        $this->runJob($generation)->assertNotReleased();

        $generation->refresh();
        $this->assertSame(GenerationStatus::Queued, $generation->status);
        $this->assertSame($payload['id'], $generation->pixelforge_id);
        $this->assertEquals($payload['rates'], $generation->rates);
        $this->assertTrue($generation->accepted_at->equalTo(now()));
        $this->assertSame(1, $generation->submit_attempts);
        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $job): bool => $job->generationId === $generation->id && $job->delay->equalTo(now()->addMinutes(2)) && $job->afterCommit === true);
        Queue::assertNotPushed(StoreGeneratedImage::class);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $generation->id)
            && $request->data() === [
                'prompt' => $generation->prompt, 'size' => $generation->size, 'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->id, 'user_id' => (string) $generation->user_id],
            ]);
    }

    public function test_a_replayed_success_is_settled_at_the_exact_fixture_price(): void
    {
        $generation = $this->heldGeneration();
        Http::fake([self::BASE_URL.'/generations' => Http::response($this->snapshot($generation, 'succeeded'), 202, ['Idempotent-Replayed' => 'true'])]);

        $this->runJob($generation);

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
        $this->assertSame(305_510_000, $generation->provider_cost_nanodollars);
        $this->assertSame(381_900_000, $generation->price_nanodollars);
        $this->assertDatabaseHas('ledger_entries', ['generation_id' => $generation->id, 'type' => 'charge', 'amount_nanodollars' => -381_900_000]);
        $this->assertDatabaseHas('ledger_entries', ['generation_id' => $generation->id, 'type' => 'hold_release', 'amount_nanodollars' => 86_500_000]);
        Queue::assertPushed(StoreGeneratedImage::class, 1);
    }

    #[DataProvider('rateLimits')]
    public function test_transient_rate_limits_respect_retry_after_and_jitter(string $code, ?int $retryAfter): void
    {
        $generation = $this->heldGeneration();
        Http::fake([self::BASE_URL.'/generations' => Http::response(self::fixture('error-'.str_replace('_', '-', $code)), 429, $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter])]);

        $job = $this->runJob($generation);
        $job->assertReleased();
        $this->assertGreaterThanOrEqual($retryAfter ?? 5, $job->job->releaseDelay);
        $this->assertLessThanOrEqual(($retryAfter ?? 5) + 2, $job->job->releaseDelay);
        $job->assertReleased($job->job->releaseDelay);
        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
        $this->assertSame(1, $generation->submit_attempts);
        $this->assertSame(1, $generation->ledgerEntries()->count());
    }

    public static function rateLimits(): array
    {
        return [['rate_limit_exceeded', 17], ['concurrency_limit_exceeded', 5], ['rate_limit_exceeded', null]];
    }

    #[DataProvider('refusals')]
    public function test_refusals_release_the_hold_without_retrying(int $status, string $code, string $reason, string $message): void
    {
        Log::spy();
        $generation = $this->heldGeneration();
        $payload = self::fixture('error-'.str_replace('_', '-', $code));
        Http::fake([self::BASE_URL.'/generations' => Http::response($payload, $status)]);

        $this->runJob($generation)->assertNotReleased();

        $this->assertFailedWithoutCharge($generation, $reason, $message);
        Http::assertSentCount(1);
        if ($reason === 'provider_error') {
            Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['status'] === $status && $context['error_code'] === $code)->once();
        }
    }

    public static function refusals(): array
    {
        return [
            [400, 'content_policy_violation', 'prompt_rejected', 'Pixelforge rejected this prompt under its content policy. Nothing was charged.'],
            [429, 'daily_limit_exceeded', 'daily_quota_reached', "Pixelforge's daily generation quota is used up. Nothing was charged; please try again after midnight UTC."],
            [422, 'validation_failed', 'provider_error', 'Pixelforge could not accept this generation. Nothing was charged.'],
            [401, 'invalid_api_key', 'provider_error', 'Pixelforge could not accept this generation. Nothing was charged.'],
            [409, 'idempotency_key_reused', 'provider_error', 'Pixelforge could not accept this generation. Nothing was charged.'],
            [429, 'not_found', 'provider_error', 'Pixelforge could not accept this generation. Nothing was charged.'],
        ];
    }

    public function test_six_overloaded_posts_back_off_then_fail_and_always_use_the_same_key_and_parameters(): void
    {
        $generation = $this->heldGeneration();
        Http::fake([self::BASE_URL.'/generations' => Http::response(self::fixture('error-overloaded'), 503, ['Retry-After' => '2'])]);

        foreach ([2, 4, 8, 16, 32] as $index => $delay) {
            $this->runJob($generation)->assertReleased($delay);
            $this->assertSame($index + 1, $generation->refresh()->submit_attempts);
            $this->assertSame(GenerationStatus::Pending, $generation->status);
        }
        $this->runJob($generation)->assertNotReleased();
        $this->runJob($generation)->assertNotReleased();

        $this->assertFailedWithoutCharge($generation, 'provider_busy', 'Pixelforge was too busy to accept this generation. Nothing was charged; please try again.');
        $this->assertSame(6, $generation->submit_attempts);
        Http::assertSentCount(6);
        foreach (Http::recorded() as [$request]) {
            $this->assertTrue($request->hasHeader('Idempotency-Key', $generation->id));
            $this->assertSame(['generation_id' => $generation->id, 'user_id' => (string) $generation->user_id], $request['metadata']);
            $this->assertSame($generation->prompt, $request['prompt']);
        }
    }

    #[DataProvider('retryableFailures')]
    public function test_other_transient_failures_are_released(string $failure, int $delay): void
    {
        $generation = $this->heldGeneration();
        $response = match ($failure) {
            'connection' => Http::failedConnection(),
            'invalid' => Http::response('null', 202),
            'invalid_fields' => Http::response($this->snapshot($generation, changes: ['rates' => null]), 202),
            'server' => Http::response(self::fixture('error-overloaded'), 502),
            'retry_after' => Http::response(self::fixture('error-overloaded'), 503, ['Retry-After' => '40']),
            'capped' => Http::response(self::fixture('error-overloaded'), 503, ['Retry-After' => '100']),
        };
        Http::fake([self::BASE_URL.'/generations' => $response]);

        $this->runJob($generation)->assertReleased($delay);

        $this->assertSame(GenerationStatus::Pending, $generation->refresh()->status);
        $this->assertSame(1, $generation->submit_attempts);
    }

    public static function retryableFailures(): array
    {
        return [['connection', 2], ['invalid', 2], ['invalid_fields', 2], ['server', 2], ['retry_after', 40], ['capped', 60]];
    }

    public function test_the_sixth_rate_limit_attempt_is_also_the_last(): void
    {
        $generation = $this->heldGeneration(['submit_attempts' => 5]);
        Http::fake([self::BASE_URL.'/generations' => Http::response(self::fixture('error-rate-limit-exceeded'), 429)]);
        $this->runJob($generation)->assertNotReleased();
        $this->assertFailedWithoutCharge($generation, 'provider_busy', 'Pixelforge was too busy to accept this generation. Nothing was charged; please try again.');
    }

    public function test_three_in_flight_generations_across_users_release_without_a_post_or_attempt(): void
    {
        $generation = $this->heldGeneration();
        Generation::factory()->queued()->count(2)->create(['accepted_at' => now()->subMinute()]);
        Generation::factory()->processing()->create(['accepted_at' => now()->subMinute()]);
        Generation::factory()->succeeded()->create();

        $this->runJob($generation)->assertReleased(5);

        Http::assertNothingSent();
        $this->assertSame(0, $generation->refresh()->submit_attempts);
    }

    public function test_three_recent_processing_generations_block_submission(): void
    {
        $generation = $this->heldGeneration();
        Generation::factory()->processing()->count(3)->create(['accepted_at' => now()->subMinute()]);

        $this->runJob($generation)->assertReleased(5);

        Http::assertNothingSent();
        $this->assertSame(0, $generation->refresh()->submit_attempts);
    }

    public function test_three_stuck_processing_generations_do_not_block_submission(): void
    {
        $generation = $this->heldGeneration();
        Generation::factory()->processing()->count(3)->create(['accepted_at' => now()->subHours(2)]);
        Http::fake([self::BASE_URL.'/generations' => Http::response($this->snapshot($generation), 202)]);

        $this->runJob($generation)->assertNotReleased();

        Http::assertSentCount(1);
        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
        $this->assertSame(1, $generation->submit_attempts);
    }

    public function test_an_unaccepted_generation_older_than_fifteen_minutes_is_abandoned(): void
    {
        $this->freezeSecond();
        $generation = $this->heldGeneration(['created_at' => now()->subMinutes(15)->subSecond()]);
        $this->runJob($generation)->assertNotReleased();

        $this->assertFailedWithoutCharge($generation, 'provider_busy', 'Pixelforge was too busy to accept this generation. Nothing was charged; please try again.');
        Http::assertNothingSent();
    }

    #[DataProvider('nonPendingStatuses')]
    public function test_non_pending_generations_are_never_submitted(string $status): void
    {
        $generation = $this->heldGeneration(['status' => $status]);
        $this->runJob($generation)->assertNotReleased();
        Http::assertNothingSent();
        $this->assertSame(0, $generation->refresh()->submit_attempts);
    }

    public static function nonPendingStatuses(): array
    {
        return [['queued'], ['processing'], ['succeeded'], ['failed']];
    }

    public function test_retry_deadline_is_fixed_at_twenty_minutes_after_dispatch(): void
    {
        $this->freezeSecond();
        $deadline = now()->addMinutes(20);
        $job = unserialize(serialize(new SubmitGeneration('00000000-0000-4000-8000-000000000001')));
        $this->travel(3)->minutes();

        $this->assertTrue($deadline->equalTo($job->retryUntil()));
    }

    public function test_failed_callback_settles_only_pending_generations_and_logs_the_error(): void
    {
        Log::spy();
        $generation = $this->heldGeneration();
        $job = new SubmitGeneration($generation->id);
        $exception = new RuntimeException('Worker exhausted.');

        $job->failed($exception);
        $job->failed($exception);

        $this->assertFailedWithoutCharge($generation, 'provider_error', 'Pixelforge could not accept this generation. Nothing was charged.');
        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['exception'] === $exception)->twice();
    }

    public function test_failed_callback_does_not_settle_an_accepted_generation(): void
    {
        $generation = $this->heldGeneration(['status' => GenerationStatus::Queued]);
        (new SubmitGeneration($generation->id))->failed(new RuntimeException('Worker exhausted.'));
        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
        $this->assertSame(1, $generation->ledgerEntries()->count());
    }

    public function test_a_refusal_cannot_overwrite_a_snapshot_that_arrived_during_the_post(): void
    {
        $generation = $this->heldGeneration();
        Http::fake([self::BASE_URL.'/generations' => function () use ($generation) {
            app(ApplyGenerationSnapshot::class)->handle($this->data($generation, 'succeeded'));

            return Http::response(self::fixture('error-invalid-api-key'), 401);
        }]);

        $this->runJob($generation);

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
        $this->assertNull($generation->error_code);
    }

    public function test_a_generation_with_six_recorded_attempts_is_not_posted_again(): void
    {
        $generation = $this->heldGeneration(['submit_attempts' => 6]);
        $this->runJob($generation)->assertNotReleased();

        $this->assertFailedWithoutCharge($generation, 'provider_busy', 'Pixelforge was too busy to accept this generation. Nothing was charged; please try again.');
        Http::assertNothingSent();
        $this->assertSame(6, $generation->submit_attempts);
    }

    public function test_exactly_fifteen_minutes_old_is_still_eligible_for_submission(): void
    {
        $this->freezeSecond();
        $generation = $this->heldGeneration(['created_at' => now()->subMinutes(15)]);
        Http::fake([self::BASE_URL.'/generations' => Http::response($this->snapshot($generation), 202)]);

        $this->runJob($generation)->assertNotReleased();

        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
        Http::assertSentCount(1);
    }

    private function runJob(Generation $generation): SubmitGeneration
    {
        $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        return $job;
    }
}
