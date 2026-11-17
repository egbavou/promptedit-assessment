<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Usd;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'status' => $this->status->value,
            'status_label' => ucfirst($this->status->value),
            'is_final' => $this->status->isFinal(),
            'estimate' => Usd::display($this->estimated_min_nanodollars).' – '.Usd::display($this->estimated_max_nanodollars),
            'price' => $this->price_nanodollars === null ? null : Usd::display($this->price_nanodollars),
            'error_message' => $this->status === GenerationStatus::Failed
                ? (FailureReason::tryFrom($this->error_code ?? '') ?? FailureReason::GenerationFailed)->message() : null,
            'image_url' => $this->display_image_url,
            'image_expired' => $this->status === GenerationStatus::Succeeded && $this->image_path === null
                && $this->image_url_expires_at !== null && ! $this->image_url_expires_at->isFuture(),
            'created_at' => $this->created_at?->toIso8601String(),
            'show_url' => route('generations.show', $this->id),
            'pixelforge_id' => $this->pixelforge_id,
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'reference_images' => $this->reference_images,
        ];
    }
}
