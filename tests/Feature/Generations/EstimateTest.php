<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;

class EstimateTest extends GenerationTestCase
{
    public function test_it_returns_exact_user_prices_and_available_credits(): void
    {
        $this->fundedUser();

        $this->postJson(route('generations.estimate'), $this->payload())->assertOk()->assertExactJson([
            'min_nanodollars' => 58_300_000,
            'max_nanodollars' => 86_500_000,
            'estimate' => '$0.0583 – $0.0865',
            'min_price' => '0.0583',
            'max_price' => '0.0865',
            'available_nanodollars' => 5_000_000_000,
            'can_afford' => true,
        ]);

        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
        Queue::assertNothingPushed();
    }

    public function test_it_counts_reference_images(): void
    {
        $this->fundedUser();

        $this->postJson(route('generations.estimate'), [...$this->payload(), 'reference_images' => array_fill(0, 3, 'https://example.test/a.png')])
            ->assertOk()->assertExactJson([
                'min_nanodollars' => 87_000_000,
                'max_nanodollars' => 115_200_000,
                'estimate' => '$0.0870 – $0.1152',
                'min_price' => '0.0870',
                'max_price' => '0.1152',
                'available_nanodollars' => 5_000_000_000,
                'can_afford' => true,
            ]);
    }

    #[DataProvider('balances')]
    public function test_affordability_uses_the_maximum_price(int $balance, bool $expected): void
    {
        $this->fundedUser($balance);

        $this->postJson(route('generations.estimate'), $this->payload())->assertOk()
            ->assertJsonPath('can_afford', $expected)->assertJsonPath('available_nanodollars', $balance);
    }

    public static function balances(): array
    {
        return [[0, false], [86_499_999, false], [86_500_000, true]];
    }

    #[DataProvider('emptyPrompts')]
    public function test_an_empty_or_absent_prompt_uses_zero_characters(array $payload): void
    {
        $this->fundedUser();

        $this->postJson(route('generations.estimate'), $payload)->assertOk()
            ->assertJsonPath('min_nanodollars', 58_200_000)->assertJsonPath('max_nanodollars', 86_300_000)
            ->assertJsonPath('min_price', '0.0582')->assertJsonPath('max_price', '0.0863');
    }

    public static function emptyPrompts(): array
    {
        return [[[]], [['prompt' => '']], [['prompt' => null]]];
    }

    #[DataProvider('invalidPayloads')]
    public function test_it_uses_the_shared_validation_rules(array $changes, string $error): void
    {
        $this->fundedUser();

        $this->postJson(route('generations.estimate'), array_replace($this->payload(), $changes))
            ->assertUnprocessable()->assertJsonValidationErrors($error);

        Http::assertNothingSent();
    }

    public static function invalidPayloads(): array
    {
        return array_filter(CreateGenerationTest::invalidPayloads(), fn (array $case): bool => ! in_array($case[1], ['prompt', 'missing_prompt'], true)) + [
            'too long prompt' => [['prompt' => str_repeat('é', 4001)], 'prompt'],
            'non-string prompt' => [['prompt' => ['a']], 'prompt'],
        ];
    }

    public function test_validation_is_json_even_without_an_accept_header(): void
    {
        $this->fundedUser();

        $this->post(route('generations.estimate'), ['quality' => 'invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('quality');
    }

    public function test_unavailable_pricing_returns_a_503_json_response(): void
    {
        $this->fundedUser();
        $this->pricingUnavailable = true;

        $this->postJson(route('generations.estimate'), $this->payload())->assertStatus(503)->assertExactJson([
            'message' => 'Pricing is temporarily unavailable. Nothing was charged; please try again in a minute.',
        ]);
    }

    public function test_guests_cannot_estimate(): void
    {
        $this->postJson(route('generations.estimate'), $this->payload())->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_unverified_users_can_estimate(): void
    {
        $this->actingAs(User::factory()->unverified()->create());

        $this->postJson(route('generations.estimate'), $this->payload())->assertOk()
            ->assertJsonPath('max_nanodollars', 86_500_000)->assertJsonPath('can_afford', false);
    }
}
