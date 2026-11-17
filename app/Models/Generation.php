<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GenerationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $pixelforge_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property string $size
 * @property string $quality
 * @property list<string> $reference_images
 * @property int $estimated_min_nanodollars
 * @property int $estimated_max_nanodollars
 * @property array<string, string>|null $rates
 * @property array<string, int>|null $usage
 * @property int|null $provider_cost_nanodollars
 * @property int|null $price_nanodollars
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $image_path
 * @property-read string|null $display_image_url
 * @property int $submit_attempts
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'id', 'user_id', 'pixelforge_id', 'status', 'prompt', 'size', 'quality',
    'reference_images', 'estimated_min_nanodollars', 'estimated_max_nanodollars',
    'rates', 'usage', 'provider_cost_nanodollars', 'price_nanodollars',
    'error_code', 'error_message', 'image_url', 'image_url_expires_at', 'image_path',
    'submit_attempts', 'accepted_at', 'started_at', 'completed_at', 'last_synced_at',
])]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUuids;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return Attribute<string|null, never> */
    protected function displayImageUrl(): Attribute
    {
        return Attribute::get(function (): ?string {
            if ($this->image_path !== null) {
                return route('generations.image', $this);
            }

            return $this->image_url_expires_at?->isFuture() ? $this->image_url : null;
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'estimated_min_nanodollars' => 'integer',
            'estimated_max_nanodollars' => 'integer',
            'provider_cost_nanodollars' => 'integer',
            'price_nanodollars' => 'integer',
            'submit_attempts' => 'integer',
            'image_url_expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
