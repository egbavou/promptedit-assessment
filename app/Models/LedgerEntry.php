<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LedgerEntryType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $generation_id
 * @property LedgerEntryType $type
 * @property int $amount_nanodollars
 * @property string|null $idempotency_key
 * @property CarbonImmutable $created_at
 */
#[Fillable(['user_id', 'generation_id', 'type', 'amount_nanodollars', 'idempotency_key'])]
class LedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_nanodollars' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
