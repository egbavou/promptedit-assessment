<?php

declare(strict_types=1);

namespace Tests\Feature\Webhooks;

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Jobs\StoreGeneratedImage;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Generations\PipelineTestCase;

class RealPixelforgeWebhookTest extends PipelineTestCase
{
    public function test_real_duplicate_deliveries_settle_once_even_when_the_attempt_header_stays_one(): void
    {
        $processing = $this->capture('processing');
        $succeeded = $this->capture('succeeded');
        // Preserve the real generation UUID so the unmodified metadata locates this row.
        $generation = $this->heldGeneration([
            'id' => $succeeded['event']['data']['object']['metadata']['generation_id'],
            'estimated_max_nanodollars' => 21_900_000,
        ]);

        foreach ([$processing, $succeeded] as $capture) {
            $this->assertCount(2, $capture['deliveries']);
            foreach ($capture['deliveries'] as $delivery) {
                $this->assertSame(['1'], $delivery['attempt']);
                $this->assertSame(204, $delivery['status_code']);
                $body = json_encode($capture['event'], JSON_THROW_ON_ERROR);
                $timestamp = now()->getTimestamp();
                $signature = hash_hmac('sha256', $timestamp.'.'.$body, 'test-webhook-secret');
                $this->call('POST', '/webhooks/pixelforge', server: [
                    'CONTENT_TYPE' => 'application/json',
                    'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
                    'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => $delivery['attempt'][0],
                ], content: $body)->assertNoContent();
            }
        }

        $generation->refresh();
        $this->assertSame(GenerationStatus::Succeeded, $generation->status);
        $this->assertSame(15_520_000, $generation->provider_cost_nanodollars);
        $this->assertSame(19_400_000, $generation->price_nanodollars);
        $this->assertSame(2, WebhookEvent::query()->count());
        foreach ([LedgerEntryType::Hold, LedgerEntryType::HoldRelease, LedgerEntryType::Charge] as $type) {
            $this->assertSame(1, $generation->ledgerEntries()->where('type', $type)->count());
        }
        Queue::assertPushed(StoreGeneratedImage::class, 1);
    }

    private function capture(string $status): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/pixelforge/real-webhook-duplicate-'.$status.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
