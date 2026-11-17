<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyGenerationSnapshot;
use App\Actions\SettleGeneration;
use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pixelforge\Exceptions\PixelforgeRequestFailed;
use App\Pixelforge\Exceptions\PixelforgeUnreachable;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    private CarbonImmutable $retryDeadline;

    public function __construct(public string $generationId)
    {
        $this->retryDeadline = CarbonImmutable::now()->addMinutes(20);
    }

    public function retryUntil(): CarbonImmutable
    {
        return $this->retryDeadline;
    }

    public function failed(Throwable $exception): void
    {
        $this->settlePending(FailureReason::ProviderError, app(SettleGeneration::class));
        Log::error('The generation submission job failed.', ['generation_id' => $this->generationId, 'exception' => $exception]);
    }

    public function handle(PixelforgeClient $client, ApplyGenerationSnapshot $apply, SettleGeneration $settle): void
    {
        $generation = Generation::query()->find($this->generationId);
        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        if ($generation->accepted_at === null && $generation->created_at?->lt(now()->subMinutes(15))) {
            $this->settlePending(FailureReason::ProviderBusy, $settle);

            return;
        }

        if (Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->where('accepted_at', '>=', now()->subHour())
            ->count() >= 3) {
            $this->release(5);

            return;
        }

        $generation = DB::transaction(function () use ($settle): ?Generation {
            $generation = Generation::query()->whereKey($this->generationId)->lockForUpdate()->first();
            if ($generation === null || $generation->status !== GenerationStatus::Pending) {
                return null;
            }
            if ($generation->submit_attempts >= 6) {
                $reason = FailureReason::ProviderBusy;
                $settle->fail($generation, $reason->value, $reason->message());

                return null;
            }
            $generation->increment('submit_attempts');

            return $generation;
        });
        if ($generation === null) {
            return;
        }

        try {
            $data = $client->createGeneration($generation->id, [
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images,
                'metadata' => ['generation_id' => $generation->id, 'user_id' => (string) $generation->user_id],
            ]);
            $apply->handle($data);
        } catch (PixelforgeUnreachable|InvalidPixelforgePayload) {
            $this->retryOrFail($generation, min(60, 2 ** $generation->submit_attempts), $settle);
        } catch (PixelforgeRequestFailed $exception) {
            if ($exception->status === 429 && in_array($exception->errorCode, ['rate_limit_exceeded', 'concurrency_limit_exceeded'], true)) {
                $this->retryOrFail($generation, ($exception->retryAfter ?? 5) + random_int(0, 2), $settle);
            } elseif ($exception->status >= 500) {
                $this->retryOrFail($generation, min(60, max($exception->retryAfter ?? 0, 2 ** $generation->submit_attempts)), $settle);
            } else {
                $reason = match (true) {
                    $exception->status === 400 && $exception->errorCode === 'content_policy_violation' => FailureReason::PromptRejected,
                    $exception->status === 429 && $exception->errorCode === 'daily_limit_exceeded' => FailureReason::DailyQuotaReached,
                    default => FailureReason::ProviderError,
                };
                if ($reason === FailureReason::ProviderError) {
                    Log::error('Pixelforge refused a generation submission.', [
                        'generation_id' => $this->generationId,
                        'status' => $exception->status,
                        'error_code' => $exception->errorCode,
                    ]);
                }
                $this->settlePending($reason, $settle);
            }
        }
    }

    private function retryOrFail(Generation $generation, int $delay, SettleGeneration $settle): void
    {
        if ($generation->submit_attempts >= 6) {
            $this->settlePending(FailureReason::ProviderBusy, $settle);
        } elseif ($generation->refresh()->status === GenerationStatus::Pending) {
            $this->release($delay);
        }
    }

    private function settlePending(FailureReason $reason, SettleGeneration $settle): void
    {
        DB::transaction(function () use ($reason, $settle): void {
            $generation = Generation::query()->whereKey($this->generationId)->lockForUpdate()->first();
            if ($generation !== null && $generation->status === GenerationStatus::Pending) {
                $settle->fail($generation, $reason->value, $reason->message());
            }
        });
    }
}
