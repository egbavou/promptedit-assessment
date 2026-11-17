<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Actions\CreateGeneration;
use App\Billing\InsufficientCredits;
use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class CreateGenerationTest extends GenerationTestCase
{
    public function test_it_creates_a_pending_generation_and_reserves_the_maximum_user_price(): void
    {
        $user = $this->fundedUser();
        $payload = [...$this->payload(), 'reference_images' => []];

        $response = $this->post(route('generations.store'), $payload);

        $response->assertRedirect(route('generations.index'))->assertSessionHas('success');
        $this->assertDatabaseCount('generations', 1);
        $generation = Generation::query()->sole();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame($payload['prompt'], $generation->prompt);
        $this->assertSame(58_300_000, $generation->estimated_min_nanodollars);
        $this->assertSame(86_500_000, $generation->estimated_max_nanodollars);
        $hold = $generation->ledgerEntries()->sole();
        $this->assertSame(LedgerEntryType::Hold, $hold->type);
        $this->assertSame(-86_500_000, $hold->amount_nanodollars);
        $this->assertSame(4_913_500_000, app(Ledger::class)->available($user));
        $this->assertSame(86_500_000, app(Ledger::class)->reserved($user));
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generationId === $generation->id && $job->afterCommit === true);
        Queue::assertPushed(SubmitGeneration::class, 1);
        Http::assertSentCount(1);

        $this->get(route('generations.index'))->assertInertia(fn (AssertableInertia $page) => $page->has('flash.success'));
    }

    public function test_it_applies_defaults_only_when_fields_are_absent(): void
    {
        $this->fundedUser();

        $this->post(route('generations.store'), ['prompt' => str_repeat('a', 100)])->assertSessionHasNoErrors();

        $generation = Generation::query()->sole();
        $this->assertSame('1024x1024', $generation->size);
        $this->assertSame('medium', $generation->quality);
        $this->assertSame([], $generation->reference_images);
    }

    public function test_it_stores_the_validated_options_and_reference_images(): void
    {
        $this->fundedUser();
        $images = ['http://example.test/a.png', 'https://example.test/b.png'];

        $this->post(route('generations.store'), [...$this->payload(), 'size' => '1024x1536', 'quality' => 'high', 'reference_images' => $images])->assertSessionHasNoErrors();

        $generation = Generation::query()->sole();
        $this->assertSame('1024x1536', $generation->size);
        $this->assertSame('high', $generation->quality);
        $this->assertSame($images, $generation->reference_images);
    }

    public function test_insufficient_credits_create_nothing_and_explain_the_balance(): void
    {
        $this->fundedUser(10_000_000);

        $this->from(route('generations.index'))->post(route('generations.store'), $this->payload())
            ->assertRedirect(route('generations.index'))
            ->assertSessionHasErrors(['balance' => 'Not enough credits: this generation can cost up to $0.0865 and you have $0.0100 available.']);

        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
        Queue::assertNothingPushed();
    }

    public function test_a_balance_one_nanodollar_below_the_maximum_is_refused(): void
    {
        $this->fundedUser(86_499_999);

        $this->post(route('generations.store'), $this->payload())->assertSessionHasErrors('balance');

        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
        Queue::assertNothingPushed();
    }

    public function test_an_exactly_equal_balance_is_accepted(): void
    {
        $user = $this->fundedUser(86_500_000);

        $this->post(route('generations.store'), $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(0, app(Ledger::class)->available($user));
        $this->assertDatabaseCount('generations', 1);
    }

    public function test_the_action_exposes_available_and_required_credit_amounts(): void
    {
        $user = $this->fundedUser(10_000_000);

        try {
            app(CreateGeneration::class)->handle($user, [...$this->payload(), 'reference_images' => []]);
            $this->fail('Expected insufficient credits.');
        } catch (InsufficientCredits $exception) {
            $this->assertSame(10_000_000, $exception->available);
            $this->assertSame(86_500_000, $exception->required);
        }
    }

    public function test_it_locks_the_user_before_reading_the_balance(): void
    {
        $this->fundedUser();
        DB::enableQueryLog();

        try {
            $this->post(route('generations.store'), $this->payload())->assertSessionHasNoErrors();
            $queries = array_column(DB::getQueryLog(), 'query');
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $locks = array_filter($queries, fn (string $query): bool => preg_match('/select .* from "users" .*for update/i', $query) === 1);
        $this->assertCount(1, $locks);
        $lockIndex = array_key_first($locks);
        $balanceIndex = array_key_first(array_filter($queries, fn (string $query): bool => str_contains($query, 'sum("amount_nanodollars")')));
        $this->assertLessThan($balanceIndex, $lockIndex);
    }

    public function test_two_creations_cannot_spend_the_same_balance(): void
    {
        $user = $this->fundedUser(100_000_000);

        $this->post(route('generations.store'), $this->payload())->assertSessionHasNoErrors();
        $this->post(route('generations.store'), $this->payload())->assertSessionHasErrors('balance');

        $this->assertDatabaseCount('generations', 1);
        $this->assertDatabaseCount('ledger_entries', 2);
        $this->assertSame(13_500_000, app(Ledger::class)->available($user));
        Queue::assertPushed(SubmitGeneration::class, 1);
    }

    public function test_the_generation_is_rolled_back_if_the_hold_fails(): void
    {
        $user = $this->fundedUser();
        $ledger = $this->partialMock(Ledger::class);
        $ledger->shouldReceive('hold')->once()->andThrow(new RuntimeException('Hold failed.'));

        try {
            app(CreateGeneration::class)->handle($user, [...$this->payload(), 'reference_images' => []]);
            $this->fail('Expected the hold to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Hold failed.', $exception->getMessage());
        }

        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
        Queue::assertNothingPushed();
    }

    public function test_the_job_is_only_queued_after_the_surrounding_transaction_commits(): void
    {
        $user = $this->fundedUser();
        Queue::swap($this->originalQueueManager);
        config()->set('queue.default', 'database');

        $generation = DB::transaction(function () use ($user): Generation {
            $generation = app(CreateGeneration::class)->handle($user, [...$this->payload(), 'reference_images' => []]);
            $this->assertDatabaseCount('jobs', 0);

            return $generation;
        });

        $this->assertDatabaseCount('jobs', 1);
        $job = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertStringContainsString($generation->id, $job['data']['command']);
    }

    public function test_rolling_back_the_surrounding_transaction_discards_the_job_and_hold(): void
    {
        $user = $this->fundedUser();
        Queue::swap($this->originalQueueManager);
        config()->set('queue.default', 'database');

        try {
            DB::transaction(function () use ($user): void {
                app(CreateGeneration::class)->handle($user, [...$this->payload(), 'reference_images' => []]);
                throw new RuntimeException('Roll back.');
            });
        } catch (RuntimeException $exception) {
            $this->assertSame('Roll back.', $exception->getMessage());
        }

        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
        $this->assertDatabaseCount('jobs', 0);
    }

    #[DataProvider('invalidPayloads')]
    public function test_it_rejects_invalid_input(array $changes, string $error): void
    {
        $this->fundedUser();
        $payload = array_replace($this->payload(), $changes);
        if ($error === 'missing_prompt') {
            unset($payload['prompt']);
            $error = 'prompt';
        }

        $this->post(route('generations.store'), $payload)->assertSessionHasErrors($error);

        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public static function invalidPayloads(): array
    {
        return [
            'missing prompt' => [[], 'missing_prompt'],
            'empty prompt' => [['prompt' => ''], 'prompt'],
            'too long' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
            'multibyte too long' => [['prompt' => str_repeat('é', 4001)], 'prompt'],
            'non-string prompt' => [['prompt' => ['a']], 'prompt'],
            'size' => [['size' => '512x512'], 'size'],
            'null size' => [['size' => null], 'size'],
            'quality' => [['quality' => 'ultra'], 'quality'],
            'null quality' => [['quality' => null], 'quality'],
            'five images' => [['reference_images' => array_fill(0, 5, 'https://example.test/a.png')], 'reference_images'],
            'not a list' => [['reference_images' => ['image' => 'https://example.test/a.png']], 'reference_images'],
            'not an array' => [['reference_images' => 'https://example.test/a.png'], 'reference_images'],
            'null images' => [['reference_images' => null], 'reference_images'],
            'ftp' => [['reference_images' => ['ftp://example.test/a.png']], 'reference_images.0'],
            'javascript' => [['reference_images' => ['javascript:alert(1)']], 'reference_images.0'],
            'long URL' => [['reference_images' => ['https://example.test/'.str_repeat('a', 2030)]], 'reference_images.0'],
        ];
    }

    public function test_a_multibyte_prompt_of_exactly_4000_characters_is_accepted(): void
    {
        $this->fundedUser();
        $prompt = str_repeat('é', 4000);

        $this->post(route('generations.store'), ['prompt' => $prompt])->assertSessionHasNoErrors();

        $this->assertSame($prompt, Generation::query()->sole()->prompt);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->post(route('generations.store'), $this->payload())->assertRedirect(route('login'));
        $this->assertDatabaseCount('generations', 0);
        Queue::assertNothingPushed();
    }

    public function test_a_newly_registered_user_can_buy_credits_and_create_without_verifying_email(): void
    {
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Reviewer',
            'email' => 'reviewer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard', absolute: false));

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->email_verified_at);
        Notification::assertNothingSent();

        $this->get(route('dashboard'))->assertRedirect(route('generations.index'));
        $this->get('/generations')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Generations/Index'));

        $this->from(route('generations.index'))->post(route('credits.purchase'), [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors()->assertRedirect(route('generations.index'));
        $this->assertSame(5_000_000_000, app(Ledger::class)->available($user));

        $this->post(route('generations.store'), $this->payload())
            ->assertSessionHasNoErrors()->assertRedirect(route('generations.index'));

        $generation = Generation::query()->sole();
        $this->assertSame($user->id, $generation->user_id);
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame(4_913_500_000, app(Ledger::class)->available($user));
        $this->assertDatabaseCount('ledger_entries', 2);
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generationId === $generation->id);
        $this->assertNull($user->refresh()->email_verified_at);
    }

    public function test_unavailable_pricing_creates_nothing(): void
    {
        $this->fundedUser();
        $this->pricingUnavailable = true;

        $this->post(route('generations.store'), $this->payload())->assertSessionHasErrors([
            'pricing' => 'Pricing is temporarily unavailable. Nothing was charged; please try again in a minute.',
        ]);

        $this->assertDatabaseCount('generations', 0);
        $this->assertDatabaseCount('ledger_entries', 1);
        Queue::assertNothingPushed();
    }
}
