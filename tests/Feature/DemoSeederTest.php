<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Models\User;
use App\Pixelforge\PricingRepository;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_demo_is_repeatable_with_coherent_ledger_and_no_provider_calls(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        Http::preventStrayRequests();
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);
        $user = User::query()->where('email', 'demo@example.com')->sole();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check((string) env('DEMO_PASSWORD', 'password'), $user->password));
        $this->assertSame(5, $user->generations()->count());
        $this->assertEqualsCanonicalizing(GenerationStatus::cases(), $user->generations()->get()->pluck('status')->all());
        $this->assertSame(9, $user->ledgerEntries()->count());
        $this->assertSame(259_500_000, app(Ledger::class)->reserved($user));
        $this->assertSame(4_358_600_000, app(Ledger::class)->available($user));
        foreach ($user->generations as $generation) {
            $this->assertSame(1, $generation->ledgerEntries()->where('type', LedgerEntryType::Hold)->count());
            $this->assertSame($generation->status->isFinal() ? 1 : 0, $generation->ledgerEntries()->where('type', LedgerEntryType::HoldRelease)->count());
            $this->assertSame($generation->status === GenerationStatus::Succeeded ? 1 : 0, $generation->ledgerEntries()->where('type', LedgerEntryType::Charge)->count());
        }
        Http::assertNothingSent();
    }

    public function test_demo_estimates_use_the_fixture_as_an_offline_fallback(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config()->set('services.pixelforge.base_url', 'https://api.pixelforge.test/v1');
        Http::fake(['https://api.pixelforge.test/v1/pricing' => Http::failedConnection()]);
        $this->seed(DemoSeeder::class);
        $this->assertNull(Cache::get('pixelforge.pricing'));
        $this->assertSame(File::json(base_path('tests/Fixtures/pixelforge/doc-pricing.json')), Cache::get('pixelforge.pricing.last_known'));
        $user = User::query()->where('email', 'demo@example.com')->sole();
        $this->app->detectEnvironment(fn () => 'testing');
        $this->actingAs($user)->postJson(route('generations.estimate'), ['prompt' => str_repeat('a', 100)])
            ->assertOk()->assertJsonPath('estimate', '$0.0583 – $0.0865');
        Http::assertSentCount(1);
    }

    public function test_live_pricing_wins_after_demo_seeding(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        config()->set('services.pixelforge.base_url', 'https://api.pixelforge.test/v1');
        $payload = File::json(base_path('tests/Fixtures/pixelforge/doc-pricing.json'));
        $payload['tokens']['per_reference_image'] = 800;
        Http::fake(['https://api.pixelforge.test/v1/pricing' => Http::response($payload)]);
        $this->seed(DemoSeeder::class);

        $this->assertSame(800, app(PricingRepository::class)->current()->tokensPerReferenceImage);
        Http::assertSentCount(1);
    }

    public function test_demo_seeding_preserves_existing_live_and_last_known_pricing(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        $payload = File::json(base_path('tests/Fixtures/pixelforge/doc-pricing.json'));
        $payload['tokens']['per_reference_image'] = 800;
        Cache::put('pixelforge.pricing', $payload, now()->addMinutes(10));
        Cache::forever('pixelforge.pricing.last_known', $payload);

        $this->seed(DemoSeeder::class);

        $this->assertSame($payload, Cache::get('pixelforge.pricing'));
        $this->assertSame($payload, Cache::get('pixelforge.pricing.last_known'));
    }

    public function test_demo_seeder_does_nothing_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->seed(DemoSeeder::class);
        $this->assertDatabaseCount('users', 0);
    }
}
