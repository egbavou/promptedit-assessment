<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Enums\FailureReason;
use App\Models\Generation;
use Inertia\Testing\AssertableInertia as Assert;

class GenerationShowTest extends GenerationTestCase
{
    public function test_succeeded_fixture_has_exact_itemized_costs_and_frozen_rates(): void
    {
        $user = $this->fundedUser();
        $fixture = self::fixture('generation-succeeded');
        $generation = Generation::factory()->for($user)->succeeded()->create([
            'usage' => $fixture['usage'], 'rates' => $fixture['rates'],
            'provider_cost_nanodollars' => 305_510_000, 'price_nanodollars' => 381_900_000,
            'pixelforge_id' => $fixture['id'], 'reference_images' => $fixture['reference_images'],
            'started_at' => $fixture['started_at'], 'completed_at' => $fixture['completed_at'],
        ]);
        $this->get(route('generations.show', $generation))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Generations/Show')->where('generation.id', $generation->id)
            ->where('generation.pixelforge_id', $fixture['id'])
            ->where('generation.reference_images', $fixture['reference_images'])
            ->where('generation.started_at', '2026-11-04T14:03:13+00:00')
            ->where('generation.completed_at', '2026-11-04T14:03:29+00:00')
            ->where('breakdown.lines', [
                ['label' => 'Input text', 'tokens' => 12, 'rate' => '$5.00 / 1M tokens', 'cost' => '$0.000060000'],
                ['label' => 'Reference images', 'tokens' => 765, 'rate' => '$10.00 / 1M tokens', 'cost' => '$0.007650000'],
                ['label' => 'Output', 'tokens' => 6450, 'rate' => '$40.00 / 1M tokens', 'cost' => '$0.258000000'],
                ['label' => 'Reasoning', 'tokens' => 3184, 'rate' => '$12.50 / 1M tokens', 'cost' => '$0.039800000'],
            ])->where('breakdown.provider_cost', '$0.305510000')
            ->where('breakdown.marked_up', '$0.381887500')->where('breakdown.price', '$0.3819'));
    }

    public function test_markup_keeps_all_eleven_decimal_places_when_needed(): void
    {
        $user = $this->fundedUser();
        $generation = Generation::factory()->for($user)->succeeded()->create([
            'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 0, 'reasoning_tokens' => 0],
            'rates' => array_fill_keys(['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'], '0.001'),
            'provider_cost_nanodollars' => 1, 'price_nanodollars' => 100_000,
        ]);
        $this->get(route('generations.show', $generation))->assertInertia(fn (Assert $page) => $page
            ->where('breakdown.provider_cost', '$0.000000001')->where('breakdown.marked_up', '$0.00000000125')
            ->where('breakdown.price', '$0.0001'));
    }

    public function test_failed_generation_has_a_safe_message_and_no_breakdown(): void
    {
        $user = $this->fundedUser();
        $generation = Generation::factory()->for($user)->failed()->create([
            'error_code' => FailureReason::InternalError->value, 'error_message' => 'Private provider details',
        ]);
        $this->get(route('generations.show', $generation))->assertInertia(fn (Assert $page) => $page
            ->where('generation.error_message', FailureReason::InternalError->message())
            ->where('generation.estimate', '$0.0583 – $0.0865')->where('breakdown', null));
    }

    public function test_another_users_generation_is_forbidden(): void
    {
        $this->fundedUser();
        $this->get(route('generations.show', Generation::factory()->create()))->assertForbidden();
    }
}
