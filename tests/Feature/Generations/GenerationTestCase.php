<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Billing\Ledger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Unit\Pixelforge\PixelforgeTestCase;

abstract class GenerationTestCase extends PixelforgeTestCase
{
    use RefreshDatabase;

    protected QueueManager $originalQueueManager;

    protected bool $pricingUnavailable = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalQueueManager = Queue::getFacadeRoot();
        Queue::fake();
        Http::fake([self::BASE_URL.'/pricing' => fn () => $this->pricingUnavailable
            ? Http::response([], 500)
            : Http::response(self::fixture('pricing'))]);
    }

    protected function fundedUser(int $balance = 5_000_000_000): User
    {
        $user = User::factory()->create();

        if ($balance > 0) {
            app(Ledger::class)->purchase($user, 'test-purchase-'.$user->id, $balance);
        }

        $this->actingAs($user);

        return $user;
    }

    protected function payload(): array
    {
        return ['prompt' => str_repeat('a', 100), 'size' => '1024x1024', 'quality' => 'medium'];
    }
}
