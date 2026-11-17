<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Billing\Ledger;
use App\Models\Generation;
use Inertia\Testing\AssertableInertia as Assert;

class GenerationIndexTest extends GenerationTestCase
{
    public function test_index_contains_only_owned_generations_newest_first_and_server_options(): void
    {
        $user = $this->fundedUser();
        $old = Generation::factory()->for($user)->create(['created_at' => now()->subDay()]);
        $new = Generation::factory()->for($user)->succeeded()->create([
            'created_at' => now()->startOfMinute(),
            'price_nanodollars' => 74_500_000,
            'image_url' => 'https://example.com/expired.png',
            'image_url_expires_at' => now()->subMinute(),
        ]);
        Generation::factory()->create();
        app(Ledger::class)->hold($user, $old, 86_500_000);

        $this->get(route('generations.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $new->id)
            ->where('generations.data.0.prompt', $new->prompt)
            ->where('generations.data.0.size', '1024x1024')
            ->where('generations.data.0.quality', 'medium')
            ->where('generations.data.0.status', 'succeeded')
            ->where('generations.data.0.status_label', 'Succeeded')
            ->where('generations.data.0.is_final', true)
            ->where('generations.data.0.estimate', '$0.0583 – $0.0865')
            ->where('generations.data.0.price', '$0.0745')
            ->where('generations.data.0.error_message', null)
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.image_expired', true)
            ->where('generations.data.0.created_at', $new->created_at->toIso8601String())
            ->where('generations.data.0.show_url', route('generations.show', $new))
            ->where('generations.data.1.id', $old->id)
            ->where('generations.data.1.price', null)
            ->where('generations.data.1.image_expired', false)
            ->where('balance.available', '$4.9135')
            ->where('balance.reserved', '$0.0865')
            ->where('balance.available_nanodollars', 4_913_500_000)
            ->where('options.sizes', ['1024x1024', '1536x1024', '1024x1536'])
            ->where('options.qualities', ['low', 'medium', 'high'])
            ->where('options.max_prompt_length', 4000)
            ->where('options.max_reference_images', 4));
    }

    public function test_index_simple_paginates_twenty_generations(): void
    {
        $user = $this->fundedUser();
        Generation::factory()->for($user)->count(21)->create();
        $this->get(route('generations.index'))->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 20)->where('generations.meta.per_page', 20)
            ->where('generations.links.next', route('generations.index').'?page=2'));
        $this->get(route('generations.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page->has('generations.data', 1));
    }

    public function test_balance_is_shared_on_other_app_pages_with_exact_precision(): void
    {
        $this->fundedUser(4_925_500_001);
        $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page
            ->where('balance.available', '$4.925500001')->where('balance.reserved', '$0.0000'));
    }

    public function test_guests_must_log_in(): void
    {
        $this->get(route('generations.index'))->assertRedirect(route('login'));
    }
}
