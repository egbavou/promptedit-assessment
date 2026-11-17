<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Jobs\StoreGeneratedImage;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pixelforge\GenerationData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class ApplyGenerationSnapshot
{
    public function __construct(private SettleGeneration $settle) {}

    public function handle(GenerationData $data): ?Generation
    {
        $id = $data->metadata['generation_id'] ?? null;
        $generation = $id !== null && Str::isUuid($id) ? Generation::query()->find($id) : null;
        $generation ??= Generation::query()->where('pixelforge_id', $data->id)->first();

        if ($generation === null) {
            Log::warning('Received a Pixelforge snapshot for an unknown generation.', ['pixelforge_id' => $data->id, 'generation_id' => $id]);

            return null;
        }

        return DB::transaction(function () use ($generation, $data): Generation {
            $generation = Generation::query()->whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($generation->status->isFinal()) {
                return $generation;
            }

            $status = GenerationStatus::fromPixelforge($data->status);
            if ($status === GenerationStatus::Succeeded && $data->usage === null) {
                throw new InvalidPixelforgePayload('A succeeded generation must include usage.');
            }

            $startSync = $generation->accepted_at === null;
            $generation->pixelforge_id ??= $data->id;
            $generation->rates = $data->rates->toArray();
            if ($data->startedAt !== null) {
                $generation->started_at = $data->startedAt;
            }
            if ($data->completedAt !== null) {
                $generation->completed_at = $data->completedAt;
            }
            if ($data->imageUrl !== null) {
                $generation->image_url = $data->imageUrl;
            }
            if ($data->imageUrlExpiresAt !== null) {
                $generation->image_url_expires_at = $data->imageUrlExpiresAt;
            }
            $generation->last_synced_at = now()->toImmutable();
            if ($startSync) {
                $generation->accepted_at = now()->toImmutable();
            }

            $storeImage = false;
            if ($status->rank() > $generation->status->rank()) {
                if ($status === GenerationStatus::Succeeded) {
                    $this->settle->succeed($generation, $data->usage, $data->rates);
                    $storeImage = true;
                } elseif ($status === GenerationStatus::Failed) {
                    $reason = FailureReason::tryFrom($data->errorCode ?? '');
                    if ($reason !== FailureReason::GenerationFailed && $reason !== FailureReason::InternalError) {
                        Log::warning('A failed Pixelforge generation has a missing or unrecognized error code.', [
                            'generation_id' => $generation->id,
                            'error_code' => $data->errorCode,
                        ]);
                        $reason = FailureReason::InternalError;
                    }
                    $this->settle->fail($generation, $reason->value, $reason->message());
                } else {
                    $generation->status = $status;
                }
            }
            $generation->save();

            if ($startSync) {
                SyncGeneration::dispatch($generation->id)->delay(now()->addMinutes(2))->afterCommit();
            }
            if ($storeImage) {
                StoreGeneratedImage::dispatch($generation->id)->afterCommit();
            }

            return $generation;
        });
    }
}
