<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Billing\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_tracks_exact_available_and_reserved_balances(): void
    {
        $ledger = new Ledger;
        $user = User::factory()->create();
        $generation = Generation::factory()->for($user)->create();

        $this->assertSame(0, $ledger->available($user));
        $this->assertSame(0, $ledger->reserved($user));

        $purchase = $ledger->purchase($user, 'purchase-1', 5_000_000_000);
        $this->assertSame(LedgerEntryType::Purchase, $purchase->type);
        $this->assertSame(5_000_000_000, $purchase->amount_nanodollars);
        $this->assertSame(5_000_000_000, $ledger->available($user));
        $this->assertSame(0, $ledger->reserved($user));

        $ledger->hold($user, $generation, 86_500_000);
        $this->assertSame(4_913_500_000, $ledger->available($user));
        $this->assertSame(86_500_000, $ledger->reserved($user));

        $ledger->releaseHold($generation);
        $this->assertSame(5_000_000_000, $ledger->available($user));
        $this->assertSame(0, $ledger->reserved($user));

        $ledger->charge($generation, 74_500_000);
        $this->assertSame(4_925_500_000, $ledger->available($user));
        $this->assertSame(0, $ledger->reserved($user));
        $this->assertSame(4, $user->ledgerEntries()->count());
    }

    public function test_balances_are_scoped_to_the_user_and_unreleased_holds(): void
    {
        $ledger = new Ledger;
        $user = User::factory()->create();
        $other = User::factory()->create();
        $first = Generation::factory()->for($user)->create();
        $second = Generation::factory()->for($user)->create();
        $ledger->purchase($user, 'user-purchase', 5_000_000_000);
        $ledger->purchase($other, 'other-purchase', 10_000_000_000);
        $ledger->hold($user, $first, 86_500_000);
        $ledger->hold($user, $second, 100_000_000);
        $ledger->releaseHold($first);
        $ledger->charge($first, 74_500_000);

        $this->assertSame(4_825_500_000, $ledger->available($user));
        $this->assertSame(100_000_000, $ledger->reserved($user));
        $this->assertSame(10_000_000_000, $ledger->available($other));
        $this->assertSame(0, $ledger->reserved($other));
    }

    public function test_duplicate_settlements_leave_the_transaction_usable(): void
    {
        $ledger = new Ledger;
        $generation = Generation::factory()->create();
        $user = $generation->user;
        $ledger->purchase($user, 'purchase', 5_000_000_000);
        $ledger->hold($user, $generation, 86_500_000);

        DB::transaction(function () use ($ledger, $generation, $user): void {
            $ledger->releaseHold($generation);
            $ledger->releaseHold($generation);
            $ledger->charge($generation, 74_500_000);
            $ledger->charge($generation, 100_000_000);

            $this->assertSame(4, LedgerEntry::query()->count());
            $this->assertSame(4_925_500_000, $ledger->available($user));
            $this->assertSame(0, $ledger->reserved($user));
        });
    }

    public function test_releasing_a_missing_hold_is_a_no_op(): void
    {
        (new Ledger)->releaseHold(Generation::factory()->create());

        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_holds_do_not_check_the_balance_and_duplicate_holds_keep_the_first_amount(): void
    {
        $ledger = new Ledger;
        $generation = Generation::factory()->create();

        DB::transaction(function () use ($ledger, $generation): void {
            $ledger->hold($generation->user, $generation, 86_500_000);
            $ledger->hold($generation->user, $generation, 100_000_000);

            $this->assertSame(-86_500_000, $ledger->available($generation->user));
            $this->assertSame(86_500_000, $ledger->reserved($generation->user));
            $this->assertDatabaseCount('ledger_entries', 1);
        });
    }

    public function test_purchases_are_idempotent_and_return_the_original_entry(): void
    {
        $ledger = new Ledger;
        $user = User::factory()->create();

        DB::transaction(function () use ($ledger, $user): void {
            $first = $ledger->purchase($user, 'same-key', 5_000_000_000);
            $second = $ledger->purchase($user, 'same-key', 10_000_000_000);

            $this->assertTrue($first->is($second));
            $this->assertSame(5_000_000_000, $second->amount_nanodollars);
            $this->assertSame(5_000_000_000, $ledger->available($user));
            $this->assertDatabaseCount('ledger_entries', 1);
        });
    }

    public function test_different_purchase_keys_allow_multiple_purchases_without_a_generation(): void
    {
        $ledger = new Ledger;
        $user = User::factory()->create();
        $ledger->purchase($user, 'first', 5_000_000_000);
        $ledger->purchase($user, 'second', 5_000_000_000);

        $this->assertSame(10_000_000_000, $ledger->available($user));
        $this->assertDatabaseCount('ledger_entries', 2);
    }

    public function test_a_purchase_key_cannot_be_used_by_another_user(): void
    {
        $ledger = new Ledger;
        $user = User::factory()->create();
        $other = User::factory()->create();
        $ledger->purchase($user, 'shared-key', 5_000_000_000);

        try {
            $ledger->purchase($other, 'shared-key', 5_000_000_000);
            $this->fail('A purchase key must belong to its original user.');
        } catch (InvalidArgumentException) {
            $this->assertSame(5_000_000_000, $ledger->available($user));
            $this->assertSame(0, $ledger->available($other));
            $this->assertDatabaseCount('ledger_entries', 1);
        }
    }

    #[DataProvider('invalidAmounts')]
    public function test_amounts_must_be_positive(string $method, int $amount): void
    {
        $ledger = new Ledger;
        $generation = Generation::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        match ($method) {
            'purchase' => $ledger->purchase($generation->user, 'invalid', $amount),
            'hold' => $ledger->hold($generation->user, $generation, $amount),
            'charge' => $ledger->charge($generation, $amount),
        };
    }

    public static function invalidAmounts(): array
    {
        return [
            ['purchase', 0], ['purchase', -1],
            ['hold', 0], ['hold', -1],
            ['charge', 0], ['charge', -1],
        ];
    }

    public function test_a_hold_cannot_belong_to_a_different_user(): void
    {
        $generation = Generation::factory()->create();
        $other = User::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        (new Ledger)->hold($other, $generation, 86_500_000);
    }

    #[DataProvider('invalidEntries')]
    public function test_the_database_enforces_entry_signs(string $type, int $amount): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ledger_entries_amount_sign_check');

        DB::table('ledger_entries')->insert([
            'user_id' => $user->id,
            'type' => $type,
            'amount_nanodollars' => $amount,
            'created_at' => now(),
        ]);
    }

    public static function invalidEntries(): array
    {
        return [
            ['charge', 1], ['charge', 0],
            ['hold', 1], ['hold', 0],
            ['purchase', -1], ['purchase', 0],
            ['hold_release', -1], ['hold_release', 0],
            ['unknown', 1],
        ];
    }

    public function test_the_database_rejects_duplicate_generation_entry_types(): void
    {
        $generation = Generation::factory()->create();
        (new Ledger)->charge($generation, 74_500_000);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('ledger_entries_generation_id_type_unique');

        DB::table('ledger_entries')->insert([
            'user_id' => $generation->user_id,
            'generation_id' => $generation->id,
            'type' => 'charge',
            'amount_nanodollars' => -74_500_000,
            'created_at' => now(),
        ]);
    }
}
