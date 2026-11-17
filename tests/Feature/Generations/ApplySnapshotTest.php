<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\ApplyGenerationSnapshot;
use App\Actions\CreateGeneration;
use App\Billing\Ledger;
use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Jobs\StoreGeneratedImage;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class ApplySnapshotTest extends PipelineTestCase
{
    #[DataProvider('unrecognizedFailureCodes')]
    public function test_missing_or_unrecognized_failure_codes_release_the_hold_and_log_the_raw_code(?array $error, ?string $rawCode): void
    {
        $generation = $this->heldGeneration();
        Log::spy();

        app(ApplyGenerationSnapshot::class)->handle($this->data($generation, 'failed', ['error' => $error]));

        $this->assertFailedWithoutCharge($generation, FailureReason::InternalError->value, FailureReason::InternalError->message());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['generation_id'] === $generation->id
            && array_key_exists('error_code', $context) && $context['error_code'] === $rawCode);
        Queue::assertNotPushed(StoreGeneratedImage::class);
    }

    public static function unrecognizedFailureCodes(): array
    {
        return [
            [null, null],
            [['message' => 'Missing code.'], null],
            [['code' => 'new_provider_error', 'message' => 'Provider details.'], 'new_provider_error'],
            [['code' => 'provider_busy', 'message' => 'Provider details.'], 'provider_busy'],
        ];
    }

    public function test_a_stale_queued_snapshot_does_not_erase_non_null_fields(): void
    {
        $generation = $this->heldGeneration([
            'status' => GenerationStatus::Processing,
            'started_at' => '2026-11-04T14:03:13Z',
            'completed_at' => '2026-11-04T14:03:29Z',
            'image_url' => 'https://example.test/image.png',
            'image_url_expires_at' => '2026-11-05T14:03:29Z',
        ]);
        $fields = ['started_at', 'completed_at', 'image_url', 'image_url_expires_at'];
        $before = $generation->refresh()->only($fields);

        app(ApplyGenerationSnapshot::class)->handle($this->data($generation));

        $this->assertSame(GenerationStatus::Processing, $generation->refresh()->status);
        $this->assertEquals($before, $generation->only($fields));
    }

    public function test_an_older_status_still_records_fields_and_starts_the_sync_chain_once(): void
    {
        $this->freezeSecond();
        $generation = $this->heldGeneration(['status' => GenerationStatus::Processing]);
        $data = $this->data($generation, changes: ['started_at' => '2026-11-04T14:03:13Z']);
        $action = app(ApplyGenerationSnapshot::class);

        $result = $action->handle($data);
        $action->handle($data);

        $this->assertSame($generation->id, $result->id);
        $generation->refresh();
        $this->assertSame(GenerationStatus::Processing, $generation->status);
        $this->assertSame($data->id, $generation->pixelforge_id);
        $this->assertEquals($data->rates->toArray(), $generation->rates);
        $this->assertTrue($generation->started_at->equalTo($data->startedAt));
        $this->assertTrue($generation->last_synced_at->equalTo(now()));
        $this->assertTrue($generation->accepted_at->equalTo(now()));
        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $job): bool => $job->generationId === $generation->id
            && $job->sequence === 0 && $job->delay->equalTo(now()->addMinutes(2)) && $job->afterCommit === true);
        Queue::assertPushed(SyncGeneration::class, 1);
        Queue::assertNotPushed(StoreGeneratedImage::class);
    }

    public function test_succeeded_uses_the_objects_rates_and_settles_exactly_once(): void
    {
        $generation = $this->heldGeneration(['estimated_max_nanodollars' => 500_000_000]);
        $pricing = self::fixture('pricing');
        $pricing['rates']['output_tokens'] = '99.00';
        Cache::put('pixelforge.pricing', $pricing);
        $data = $this->data($generation, 'succeeded');
        $action = app(ApplyGenerationSnapshot::class);

        $action->handle($data);
        $action->handle($data);

        $generation->refresh();
        // 12*5000 + 765*10000 + 6450*40000 + 3184*12500 = 305510000.
        $this->assertSame(305_510_000, $generation->provider_cost_nanodollars);
        $this->assertSame(381_900_000, $generation->price_nanodollars);
        $this->assertEquals($data->raw['usage'], $generation->usage);
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertTrue($generation->completed_at->equalTo($data->completedAt));
        $this->assertSame($data->imageUrl, $generation->image_url);
        $this->assertTrue($generation->image_url_expires_at->equalTo($data->imageUrlExpiresAt));
        $this->assertSame(1, $generation->ledgerEntries()->where('type', LedgerEntryType::Charge)->count());
        $this->assertSame(-381_900_000, $generation->ledgerEntries()->where('type', LedgerEntryType::Charge)->sole()->amount_nanodollars);
        $this->assertSame(1, $generation->ledgerEntries()->where('type', LedgerEntryType::HoldRelease)->count());
        $this->assertSame(4_618_100_000, app(Ledger::class)->available($generation->user));
        $this->assertSame(0, app(Ledger::class)->reserved($generation->user));
        Queue::assertPushed(StoreGeneratedImage::class, fn (StoreGeneratedImage $job): bool => $job->generationId === $generation->id && $job->afterCommit === true);
        Queue::assertPushed(StoreGeneratedImage::class, 1);
        Http::assertNothingSent();
    }

    public function test_a_price_above_the_hold_is_logged_and_charged_in_full(): void
    {
        Log::spy();
        $generation = $this->heldGeneration();

        app(ApplyGenerationSnapshot::class)->handle($this->data($generation, 'succeeded'));

        $this->assertSame(381_900_000, $generation->refresh()->price_nanodollars);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['price_nanodollars'] === 381_900_000
            && $context['estimated_max_nanodollars'] === 86_500_000);
    }

    #[DataProvider('finalStatuses')]
    public function test_final_generations_never_change(string $status): void
    {
        $generation = $this->heldGeneration(['status' => $status]);
        $before = $generation->refresh()->getRawOriginal();

        app(ApplyGenerationSnapshot::class)->handle($this->data($generation, 'succeeded'));

        $this->assertSame($before, $generation->refresh()->getRawOriginal());
        $this->assertSame(1, $generation->ledgerEntries()->count());
        Queue::assertNothingPushed();
    }

    public static function finalStatuses(): array
    {
        return [['succeeded'], ['failed']];
    }

    #[DataProvider('missingMetadata')]
    public function test_it_falls_back_to_the_provider_id_for_missing_invalid_or_unknown_metadata(array $metadata): void
    {
        $generation = $this->heldGeneration(['pixelforge_id' => self::fixture('generation-queued')['id']]);

        $result = app(ApplyGenerationSnapshot::class)->handle($this->data($generation, changes: ['metadata' => $metadata]));

        $this->assertSame($generation->id, $result->id);
        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
    }

    public static function missingMetadata(): array
    {
        return [[[]], [['generation_id' => 'not-a-uuid']], [['generation_id' => '00000000-0000-4000-8000-000000000001']]];
    }

    public function test_an_unknown_generation_only_logs_a_warning(): void
    {
        $generation = Generation::factory()->make(['id' => '00000000-0000-4000-8000-000000000001']);
        Log::spy();

        $this->assertNull(app(ApplyGenerationSnapshot::class)->handle($this->data($generation)));

        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('ledger_entries', 0);
        Log::shouldHaveReceived('warning')->once();
        Queue::assertNothingPushed();
    }

    #[DataProvider('generationFailures')]
    public function test_a_failed_snapshot_releases_the_hold_and_stores_a_user_message(string $code, string $message): void
    {
        $generation = $this->heldGeneration();
        app(ApplyGenerationSnapshot::class)->handle($this->data($generation, 'failed', ['error' => ['code' => $code, 'message' => 'Provider details.']]));

        $this->assertFailedWithoutCharge($generation, $code, $message);
        Queue::assertNotPushed(StoreGeneratedImage::class);
    }

    public static function generationFailures(): array
    {
        return [
            ['generation_failed', 'Pixelforge could not produce an image for this prompt. Nothing was charged.'],
            ['internal_error', 'Pixelforge failed while generating. Nothing was charged; retrying usually works.'],
        ];
    }

    public function test_succeeded_without_usage_rolls_back_every_field_and_dispatch(): void
    {
        $generation = $this->heldGeneration();
        $before = $generation->refresh()->getRawOriginal();

        try {
            app(ApplyGenerationSnapshot::class)->handle($this->data($generation, 'succeeded', ['usage' => null]));
            $this->fail('Expected invalid usage.');
        } catch (InvalidPixelforgePayload $exception) {
            $this->assertStringContainsString('usage', $exception->getMessage());
        }

        $this->assertSame($before, $generation->refresh()->getRawOriginal());
        $this->assertSame(1, $generation->ledgerEntries()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_ledger_failure_rolls_back_the_final_status_and_release(): void
    {
        $generation = $this->heldGeneration();
        $before = $generation->refresh()->getRawOriginal();
        $this->partialMock(Ledger::class)->shouldReceive('charge')->once()->andThrow(new RuntimeException('Charge failed.'));

        try {
            app(ApplyGenerationSnapshot::class)->handle($this->data($generation, 'succeeded'));
            $this->fail('Expected a failed charge.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Charge failed.', $exception->getMessage());
        }

        $this->assertSame($before, $generation->refresh()->getRawOriginal());
        $this->assertSame(1, $generation->ledgerEntries()->count());
        Queue::assertNothingPushed();
    }

    public function test_jobs_wait_for_the_outer_transaction_and_are_discarded_on_rollback(): void
    {
        $generation = $this->heldGeneration();
        Queue::swap($this->originalQueueManager);
        config()->set('queue.default', 'database');

        try {
            DB::transaction(function () use ($generation): void {
                app(ApplyGenerationSnapshot::class)->handle($this->data($generation, 'succeeded'));
                $this->assertDatabaseCount('jobs', 0);
                throw new RuntimeException('Roll back.');
            });
        } catch (RuntimeException) {
            $this->assertDatabaseCount('jobs', 0);
        }

        DB::transaction(function () use ($generation): void {
            app(ApplyGenerationSnapshot::class)->handle($this->data($generation, 'succeeded'));
            $this->assertDatabaseCount('jobs', 0);
        });
        $this->assertDatabaseCount('jobs', 2);
    }

    public function test_purchase_create_submit_and_success_leave_the_exact_balance(): void
    {
        $user = $this->fundedUser();
        $generation = app(CreateGeneration::class)->handle($user, [...$this->payload(), 'reference_images' => []]);
        $this->assertSame(86_500_000, app(Ledger::class)->reserved($user));
        Http::fake([self::BASE_URL.'/generations' => Http::response($this->snapshot($generation), 202)]);

        app()->call([new SubmitGeneration($generation->id), 'handle']);
        app(ApplyGenerationSnapshot::class)->handle($this->data($generation, 'succeeded', ['usage' => [
            'input_text_tokens' => 25, 'input_image_tokens' => 0, 'output_tokens' => 1100,
            'reasoning_tokens' => 1234, 'total_tokens' => 2359,
        ]]));

        $this->assertSame(59_550_000, $generation->refresh()->provider_cost_nanodollars);
        $this->assertSame(74_500_000, $generation->price_nanodollars);
        $this->assertSame(4_925_500_000, app(Ledger::class)->available($user));
        $this->assertSame(0, app(Ledger::class)->reserved($user));
    }

    public function test_the_generation_is_locked_before_a_snapshot_is_written(): void
    {
        $generation = $this->heldGeneration();
        DB::enableQueryLog();
        try {
            app(ApplyGenerationSnapshot::class)->handle($this->data($generation, 'succeeded'));
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $locks = array_filter($queries, fn (string $query): bool => preg_match('/select .* from "generations" .*for update/i', $query) === 1);
        $this->assertCount(1, $locks);
        $writes = array_filter($queries, fn (string $query): bool => str_starts_with($query, 'update "generations"'));
        $this->assertNotEmpty($writes);
        $this->assertLessThan(array_key_first($writes), array_key_first($locks));
    }
}
