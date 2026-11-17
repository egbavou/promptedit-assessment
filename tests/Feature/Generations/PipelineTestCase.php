<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Pixelforge\GenerationData;

abstract class PipelineTestCase extends GenerationTestCase
{
    protected function heldGeneration(array $attributes = []): Generation
    {
        $user = $this->fundedUser();
        $generation = Generation::factory()->for($user)->create($attributes);
        app(Ledger::class)->hold($user, $generation, $generation->estimated_max_nanodollars);

        return $generation;
    }

    protected function snapshot(Generation $generation, string $status = 'queued', array $changes = []): array
    {
        $payload = self::fixture('generation-'.(in_array($status, ['succeeded', 'failed'], true) ? $status : 'queued'));
        $payload['metadata'] = ['generation_id' => $generation->id, 'user_id' => (string) $generation->user_id];
        $payload['status'] = $status;

        return array_replace($payload, $changes);
    }

    protected function data(Generation $generation, string $status = 'queued', array $changes = []): GenerationData
    {
        return GenerationData::fromArray($this->snapshot($generation, $status, $changes));
    }

    protected function assertFailedWithoutCharge(Generation $generation, string $code, string $message): void
    {
        $generation->refresh();
        $this->assertSame(GenerationStatus::Failed, $generation->status);
        $this->assertSame($code, $generation->error_code);
        $this->assertSame($message, $generation->error_message);
        $this->assertSame(1, $generation->ledgerEntries()->where('type', LedgerEntryType::HoldRelease)->count());
        $this->assertSame(0, $generation->ledgerEntries()->where('type', LedgerEntryType::Charge)->count());
        $this->assertSame(5_000_000_000, app(Ledger::class)->available($generation->user));
        $this->assertSame(0, app(Ledger::class)->reserved($generation->user));
    }
}
