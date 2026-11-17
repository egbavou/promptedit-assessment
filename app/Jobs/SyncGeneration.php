<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use App\Models\Generation;
use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pixelforge\Exceptions\PixelforgeRequestFailed;
use App\Pixelforge\Exceptions\PixelforgeUnreachable;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SyncGeneration implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $generationId, public int $sequence = 0) {}

    public function handle(PixelforgeClient $client, ApplyGenerationSnapshot $apply): void
    {
        $generation = Generation::query()->find($this->generationId);
        if ($generation === null || $generation->status->isFinal()) {
            return;
        }
        if ($generation->pixelforge_id === null) {
            Log::warning('Cannot sync a generation without a Pixelforge id.', ['generation_id' => $this->generationId]);

            return;
        }
        if ($generation->accepted_at !== null && $generation->accepted_at->lt(now()->subHours(24))) {
            Log::warning('Stopped syncing a generation accepted over 24 hours ago.', ['generation_id' => $this->generationId]);

            return;
        }

        try {
            $apply->handle($client->retrieveGeneration($generation->pixelforge_id));
        } catch (PixelforgeUnreachable) {
            // The next sync retries after a temporary connection failure.
        } catch (InvalidPixelforgePayload $exception) {
            Log::error('Received an invalid Pixelforge generation while syncing.', [
                'generation_id' => $this->generationId,
                'pixelforge_id' => $generation->pixelforge_id,
                'exception' => $exception,
            ]);
        } catch (PixelforgeRequestFailed $exception) {
            if ($exception->status === 404) {
                Log::error('Pixelforge could not find an accepted generation.', ['generation_id' => $this->generationId, 'pixelforge_id' => $generation->pixelforge_id]);

                return;
            }
            if ($exception->status !== 429 && $exception->status < 500) {
                throw $exception;
            }
        }

        if (! $generation->refresh()->status->isFinal()) {
            $sequence = $this->sequence + 1;
            $minutes = match ($sequence) {
                0, 1 => 2,
                2 => 5,
                3 => 10,
                default => 15,
            };
            self::dispatch($this->generationId, $sequence)->delay(now()->addMinutes($minutes))->afterCommit();
        }
    }
}
