<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use InvalidArgumentException;

class Ledger
{
    public function available(User $user): int
    {
        return (int) $user->ledgerEntries()->sum('amount_nanodollars');
    }

    public function reserved(User $user): int
    {
        return -(int) $user->ledgerEntries()
            ->whereIn('type', [LedgerEntryType::Hold, LedgerEntryType::HoldRelease])
            ->sum('amount_nanodollars');
    }

    public function purchase(User $user, string $idempotencyKey, int $amount): LedgerEntry
    {
        $this->ensurePositive($amount);

        $entry = LedgerEntry::query()->createOrFirst(
            ['idempotency_key' => $idempotencyKey],
            [
                'user_id' => $user->id,
                'type' => LedgerEntryType::Purchase,
                'amount_nanodollars' => $amount,
            ],
        );

        if ($entry->user_id !== $user->id || $entry->type !== LedgerEntryType::Purchase) {
            throw new InvalidArgumentException('The idempotency key is already used by another purchase.');
        }

        return $entry;
    }

    public function hold(User $user, Generation $generation, int $amount): void
    {
        $this->ensurePositive($amount);

        if ($generation->user_id !== $user->id) {
            throw new InvalidArgumentException('The generation must belong to the user reserving credits.');
        }

        LedgerEntry::query()->createOrFirst(
            ['generation_id' => $generation->id, 'type' => LedgerEntryType::Hold],
            ['user_id' => $user->id, 'amount_nanodollars' => -$amount],
        );
    }

    public function releaseHold(Generation $generation): void
    {
        $hold = $generation->ledgerEntries()->where('type', LedgerEntryType::Hold)->first();

        if ($hold === null) {
            return;
        }

        LedgerEntry::query()->createOrFirst(
            ['generation_id' => $generation->id, 'type' => LedgerEntryType::HoldRelease],
            ['user_id' => $hold->user_id, 'amount_nanodollars' => -$hold->amount_nanodollars],
        );
    }

    public function charge(Generation $generation, int $price): void
    {
        $this->ensurePositive($price);

        LedgerEntry::query()->createOrFirst(
            ['generation_id' => $generation->id, 'type' => LedgerEntryType::Charge],
            ['user_id' => $generation->user_id, 'amount_nanodollars' => -$price],
        );
    }

    private function ensurePositive(int $amount): void
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('The amount must be positive nanodollars.');
        }
    }
}
