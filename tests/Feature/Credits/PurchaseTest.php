<?php

declare(strict_types=1);

namespace Tests\Feature\Credits;

use App\Billing\Ledger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_http_purchase_credits_five_dollars_once(): void
    {
        $user = User::factory()->create();
        $key = (string) Str::uuid();
        $this->actingAs($user);
        for ($i = 0; $i < 2; $i++) {
            $this->from(route('generations.index'))->post(route('credits.purchase'), ['idempotency_key' => $key])
                ->assertRedirect(route('generations.index'))->assertSessionHas('success')->assertSessionHasNoErrors();
        }
        $this->assertSame(5_000_000_000, app(Ledger::class)->available($user));
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_another_users_key_is_a_validation_error_and_credits_nothing(): void
    {
        $key = (string) Str::uuid();
        app(Ledger::class)->purchase(User::factory()->create(), $key, 5_000_000_000);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('credits.purchase'), ['idempotency_key' => $key])->assertSessionHasErrors('idempotency_key');
        $this->assertSame(0, app(Ledger::class)->available($user));
        $this->assertDatabaseCount('ledger_entries', 1);
    }

    public function test_purchase_requires_a_uuid(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post(route('credits.purchase'))->assertSessionHasErrors('idempotency_key');
        $this->post(route('credits.purchase'), ['idempotency_key' => 'invalid'])->assertSessionHasErrors('idempotency_key');
        $this->assertDatabaseCount('ledger_entries', 0);
    }

    public function test_guests_must_log_in(): void
    {
        $this->post(route('credits.purchase'))->assertRedirect(route('login'));
    }
}
