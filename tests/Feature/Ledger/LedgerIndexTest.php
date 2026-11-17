<?php

declare(strict_types=1);

namespace Tests\Feature\Ledger;

use App\Billing\Ledger;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LedgerIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_entries_are_owned_newest_first_signed_and_linked(): void
    {
        $user = User::factory()->create();
        $ledger = app(Ledger::class);
        $purchase = $ledger->purchase($user, 'purchase', 5_000_000_000);
        $purchase->forceFill(['created_at' => now()->subDay()])->save();
        $generation = Generation::factory()->for($user)->create();
        $ledger->hold($user, $generation, 86_500_000);
        $ledger->releaseHold($generation);
        $ledger->charge($generation, 74_500_000);
        $ledger->purchase(User::factory()->create(), 'other', 5_000_000_000);
        $this->actingAs($user)->get(route('ledger.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Ledger/Index')->has('entries.data', 4)
            ->where('entries.data.0.type_label', 'Charge')->where('entries.data.0.amount', '−$0.0745')
            ->where('entries.data.0.show_url', route('generations.show', $generation))
            ->where('entries.data.0.prompt', $generation->prompt)
            ->has('entries.data.0.created_at')
            ->where('entries.data.1.type_label', 'Hold released')->where('entries.data.1.amount', '+$0.0865')
            ->where('entries.data.2.type_label', 'Hold')->where('entries.data.2.amount', '−$0.0865')
            ->where('entries.data.3.type_label', 'Purchase')->where('entries.data.3.amount', '+$5.0000')
            ->where('entries.data.3.show_url', null)->where('entries.data.3.prompt', null));
    }

    public function test_entries_simple_paginate_thirty_at_a_time(): void
    {
        $user = User::factory()->create();
        for ($i = 0; $i < 31; $i++) {
            app(Ledger::class)->purchase($user, 'purchase-'.$i, 5_000_000_000);
        }
        $this->actingAs($user)->get(route('ledger.index'))->assertInertia(fn (Assert $page) => $page
            ->has('entries.data', 30)->where('entries.meta.per_page', 30)
            ->where('entries.links.next', route('ledger.index').'?page=2'));
        $this->get(route('ledger.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    }

    public function test_generation_excerpts_end_with_a_single_ellipsis(): void
    {
        $user = User::factory()->create();
        $prompt = str_repeat('a', 53).' colors. More details beyond the excerpt.';
        $generation = Generation::factory()->for($user)->create(['prompt' => $prompt]);
        $ledger = app(Ledger::class);
        $ledger->purchase($user, 'purchase', 5_000_000_000);
        $ledger->hold($user, $generation, 86_500_000);

        $this->actingAs($user)->get(route('ledger.index'))->assertInertia(fn (Assert $page) => $page
            ->where('entries.data.0.prompt', str_repeat('a', 53).' colors…'));
    }

    public function test_guests_must_log_in(): void
    {
        $this->get(route('ledger.index'))->assertRedirect(route('login'));
    }
}
