<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generation_defaults_uuid_and_relationships(): void
    {
        $generation = Generation::factory()->create()->refresh();

        $this->assertTrue(Str::isUuid($generation->id));
        $this->assertFalse($generation->getIncrementing());
        $this->assertSame('string', $generation->getKeyType());
        $this->assertSame(GenerationStatus::Pending, $generation->status);
        $this->assertSame([], $generation->reference_images);
        $this->assertSame(0, $generation->submit_attempts);
        $this->assertNull($generation->pixelforge_id);
        $this->assertNull($generation->rates);
        $this->assertNull($generation->usage);
        $this->assertNull($generation->provider_cost_nanodollars);
        $this->assertNull($generation->price_nanodollars);
        $this->assertTrue($generation->is($generation->user->generations()->sole()));
        $this->assertInstanceOf(CarbonImmutable::class, $generation->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $generation->updated_at);
    }

    public function test_generation_casts_preserve_exact_money_rates_json_and_datetimes(): void
    {
        $timestamp = CarbonImmutable::parse('2026-11-17T12:34:56+01:00');
        $dates = array_fill_keys([
            'image_url_expires_at', 'accepted_at', 'started_at', 'completed_at', 'last_synced_at',
        ], $timestamp);
        $rates = ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'];
        $usage = ['input_text_tokens' => 25, 'output_tokens' => 1100, 'reasoning_tokens' => 1234];
        $generation = Generation::factory()->succeeded()->create([
            ...$dates,
            'reference_images' => ['https://example.com/reference.png'],
            'estimated_min_nanodollars' => 5_000_000_001,
            'estimated_max_nanodollars' => 6_000_000_001,
            'provider_cost_nanodollars' => 5_500_000_001,
            'price_nanodollars' => 6_000_000_001,
            'rates' => $rates,
            'usage' => $usage,
            'submit_attempts' => 2,
        ])->refresh();

        $this->assertSame(['https://example.com/reference.png'], $generation->reference_images);
        $storedRates = $generation->rates;
        $storedUsage = $generation->usage;
        ksort($rates);
        ksort($storedRates);
        ksort($usage);
        ksort($storedUsage);
        $this->assertSame($rates, $storedRates);
        $this->assertSame($usage, $storedUsage);
        $this->assertSame(5_000_000_001, $generation->estimated_min_nanodollars);
        $this->assertSame(6_000_000_001, $generation->estimated_max_nanodollars);
        $this->assertSame(5_500_000_001, $generation->provider_cost_nanodollars);
        $this->assertSame(6_000_000_001, $generation->price_nanodollars);
        $this->assertSame(2, $generation->submit_attempts);
        foreach (array_keys($dates) as $column) {
            $this->assertInstanceOf(CarbonImmutable::class, $generation->$column);
            $this->assertTrue($timestamp->equalTo($generation->$column));
        }
    }

    #[DataProvider('statuses')]
    public function test_factory_has_a_state_for_every_status(string $state): void
    {
        $generation = Generation::factory()->$state()->create()->refresh();

        $this->assertSame(GenerationStatus::from($state), $generation->status);
    }

    public static function statuses(): array
    {
        return [['pending'], ['queued'], ['processing'], ['succeeded'], ['failed']];
    }

    public function test_users_are_not_admins_by_default_and_have_an_admin_factory_state(): void
    {
        $this->assertFalse(User::factory()->create()->refresh()->is_admin);
        $this->assertTrue(User::factory()->admin()->create()->refresh()->is_admin);
    }

    public function test_ledger_entry_casts_timestamps_and_relationships(): void
    {
        $generation = Generation::factory()->create();
        (new Ledger)->hold($generation->user, $generation, 5_000_000_001);
        $entry = $generation->ledgerEntries()->sole();

        $this->assertSame(LedgerEntryType::Hold, $entry->type);
        $this->assertSame(-5_000_000_001, $entry->amount_nanodollars);
        $this->assertInstanceOf(CarbonImmutable::class, $entry->created_at);
        $this->assertTrue($entry->user->is($generation->user));
        $this->assertTrue($entry->generation->is($generation));
        $this->assertTrue($entry->is($generation->user->ledgerEntries()->sole()));
        $this->assertFalse(Schema::hasColumn('ledger_entries', 'updated_at'));
    }

    public function test_webhook_events_use_provider_ids_json_and_only_received_at(): void
    {
        $payload = ['id' => 'evt_test', 'data' => ['rates' => ['reasoning' => '12.50']]];
        $receivedAt = CarbonImmutable::parse('2026-11-17T12:34:56+01:00');
        $event = WebhookEvent::query()->create([
            'id' => 'evt_test',
            'type' => 'generation.succeeded',
            'pixelforge_generation_id' => 'gen_test',
            'payload' => $payload,
            'received_at' => $receivedAt,
        ])->refresh();

        $this->assertSame('evt_test', $event->getKey());
        $this->assertSame('string', $event->getKeyType());
        $this->assertFalse($event->getIncrementing());
        $this->assertFalse($event->timestamps);
        $this->assertSame($payload, $event->payload);
        $this->assertInstanceOf(CarbonImmutable::class, $event->received_at);
        $this->assertTrue($receivedAt->equalTo($event->received_at));
        $this->assertFalse(Schema::hasColumn('webhook_events', 'created_at'));
        $this->assertFalse(Schema::hasColumn('webhook_events', 'updated_at'));
    }

    public function test_postgresql_column_types_defaults_and_indexes(): void
    {
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $generations = collect(Schema::getColumns('generations'))->keyBy('name');
        $entries = collect(Schema::getColumns('ledger_entries'))->keyBy('name');
        $events = collect(Schema::getColumns('webhook_events'))->keyBy('name');
        $this->assertSame('uuid', $generations['id']['type_name']);
        foreach (['estimated_min_nanodollars', 'estimated_max_nanodollars', 'provider_cost_nanodollars', 'price_nanodollars'] as $column) {
            $this->assertSame('int8', $generations[$column]['type_name']);
        }
        $this->assertSame('int8', $entries['amount_nanodollars']['type_name']);
        foreach (['reference_images', 'rates', 'usage'] as $column) {
            $this->assertSame('jsonb', $generations[$column]['type_name']);
        }
        $this->assertSame('jsonb', $events['payload']['type_name']);
        foreach (['image_url_expires_at', 'accepted_at', 'started_at', 'completed_at', 'last_synced_at', 'created_at', 'updated_at'] as $column) {
            $this->assertSame('timestamptz', $generations[$column]['type_name']);
        }
        $this->assertSame('timestamptz', $entries['created_at']['type_name']);
        $this->assertSame('timestamptz', $events['received_at']['type_name']);
        $this->assertTrue(Schema::hasIndex('generations', ['user_id', 'created_at']));
        $this->assertTrue(Schema::hasIndex('generations', ['status']));
        $this->assertTrue(Schema::hasIndex('generations', ['pixelforge_id'], 'unique'));
        $this->assertTrue(Schema::hasIndex('ledger_entries', ['generation_id', 'type'], 'unique'));
        $this->assertTrue(Schema::hasIndex('ledger_entries', ['idempotency_key'], 'unique'));
        $this->assertTrue(Schema::hasIndex('webhook_events', ['pixelforge_generation_id']));

        $generation = Generation::factory()->make();
        DB::table('generations')->insert([
            'id' => $generation->id ?? (string) Str::uuid(),
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'prompt' => 'An image',
            'size' => '1024x1024',
            'quality' => 'medium',
            'estimated_min_nanodollars' => 58_300_000,
            'estimated_max_nanodollars' => 86_500_000,
        ]);
        $stored = Generation::query()->sole();
        $this->assertSame([], $stored->reference_images);
        $this->assertSame(0, $stored->submit_attempts);
    }
}
