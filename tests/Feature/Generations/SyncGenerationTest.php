<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGeneratedImage;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

class SyncGenerationTest extends PipelineTestCase
{
    #[DataProvider('invalidSnapshots')]
    public function test_an_invalid_snapshot_is_logged_and_the_sync_chain_continues(bool $invalidDuringApplication): void
    {
        $this->freezeSecond();
        $generation = $this->acceptedGeneration();
        $before = $generation->refresh()->getRawOriginal();
        $snapshot = $invalidDuringApplication
            ? $this->snapshot($generation, 'succeeded', ['usage' => null])
            : $this->snapshot($generation, changes: ['status' => 'invalid']);
        Http::fake([self::BASE_URL.'/generations/'.$generation->pixelforge_id => Http::response($snapshot)]);
        Log::spy();

        app()->call([new SyncGeneration($generation->id, 1), 'handle']);

        $this->assertSame($before, $generation->refresh()->getRawOriginal());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['generation_id'] === $generation->id);
        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $next): bool => $next->generationId === $generation->id
            && $next->sequence === 2 && $next->delay->equalTo(now()->addMinutes(5)));
        Queue::assertPushed(SyncGeneration::class, 1);
        Queue::assertNotPushed(StoreGeneratedImage::class);
    }

    public static function invalidSnapshots(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('sequences')]
    public function test_it_reschedules_with_the_next_delay_while_not_final(int $sequence, int $minutes): void
    {
        $this->freezeSecond();
        $generation = $this->acceptedGeneration();
        Http::fake([self::BASE_URL.'/generations/'.$generation->pixelforge_id => Http::response($this->snapshot($generation))]);

        $job = new SyncGeneration($generation->id, $sequence);
        $this->assertSame(3, $job->tries);
        app()->call([$job, 'handle']);

        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $next): bool => $next->generationId === $generation->id
            && $next->sequence === $sequence + 1 && $next->delay->equalTo(now()->addMinutes($minutes)));
        Queue::assertPushed(SyncGeneration::class, 1);
        Http::assertSentCount(1);
    }

    public static function sequences(): array
    {
        // Sequence 0 itself is initially dispatched with a two-minute delay by ApplyGenerationSnapshot.
        return [[0, 2], [1, 5], [2, 10], [3, 15], [4, 15], [100, 15]];
    }

    #[DataProvider('finalStatuses')]
    public function test_it_stops_for_a_final_generation(string $status): void
    {
        $generation = $this->acceptedGeneration(['status' => $status]);
        app()->call([new SyncGeneration($generation->id), 'handle']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function finalStatuses(): array
    {
        return [['succeeded'], ['failed']];
    }

    public function test_it_stops_when_retrieval_settles_the_generation(): void
    {
        $generation = $this->acceptedGeneration();
        Http::fake([self::BASE_URL.'/generations/'.$generation->pixelforge_id => Http::response($this->snapshot($generation, 'succeeded'))]);
        app()->call([new SyncGeneration($generation->id), 'handle']);

        $this->assertSame(GenerationStatus::Succeeded, $generation->refresh()->status);
        Queue::assertNotPushed(SyncGeneration::class);
        Queue::assertPushed(StoreGeneratedImage::class, 1);
    }

    public function test_it_stops_and_warns_after_twenty_four_hours(): void
    {
        $this->freezeSecond();
        $generation = $this->acceptedGeneration(['accepted_at' => now()->subHours(24)->subSecond()]);
        Log::spy();
        app()->call([new SyncGeneration($generation->id), 'handle']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')->once();
        $this->assertSame(GenerationStatus::Queued, $generation->refresh()->status);
    }

    public function test_missing_provider_id_is_logged_and_stops(): void
    {
        $generation = $this->heldGeneration();
        Log::spy();
        app()->call([new SyncGeneration($generation->id), 'handle']);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('warning')->once();
    }

    #[DataProvider('transientErrors')]
    public function test_transient_errors_schedule_the_next_sync(string $failure): void
    {
        $this->freezeSecond();
        $generation = $this->acceptedGeneration();
        $response = match ($failure) {
            'connection' => Http::failedConnection(),
            'rate' => Http::response(self::fixture('error-rate-limit-exceeded'), 429),
            'server' => Http::response(self::fixture('error-overloaded'), 503),
        };
        Http::fake([self::BASE_URL.'/generations/'.$generation->pixelforge_id => $response]);
        app()->call([new SyncGeneration($generation->id, 2), 'handle']);
        Queue::assertPushed(SyncGeneration::class, fn (SyncGeneration $next): bool => $next->sequence === 3 && $next->delay->equalTo(now()->addMinutes(10)));
        Queue::assertPushed(SyncGeneration::class, 1);
    }

    public static function transientErrors(): array
    {
        return [['connection'], ['rate'], ['server']];
    }

    public function test_a_not_found_response_logs_an_error_and_stops(): void
    {
        $generation = $this->acceptedGeneration();
        Log::spy();
        Http::fake([self::BASE_URL.'/generations/'.$generation->pixelforge_id => Http::response(self::fixture('error-not-found'), 404)]);
        app()->call([new SyncGeneration($generation->id), 'handle']);
        Queue::assertNothingPushed();
        Log::shouldHaveReceived('error')->once();
    }

    private function acceptedGeneration(array $changes = []): Generation
    {
        return $this->heldGeneration(array_replace([
            'status' => GenerationStatus::Queued, 'accepted_at' => now(),
            'pixelforge_id' => self::fixture('generation-queued')['id'],
        ], $changes));
    }
}
