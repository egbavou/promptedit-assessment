<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\GenerationStatus;
use App\Enums\LedgerEntryType;
use App\Pixelforge\PixelforgeGenerationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GenerationStatusTest extends TestCase
{
    #[DataProvider('statuses')]
    public function test_status_rank_and_finality(string $value, int $rank, bool $final): void
    {
        $status = GenerationStatus::from($value);

        $this->assertSame($rank, $status->rank());
        $this->assertSame($final, $status->isFinal());
    }

    public static function statuses(): array
    {
        return [
            ['pending', 0, false],
            ['queued', 1, false],
            ['processing', 2, false],
            ['succeeded', 3, true],
            ['failed', 3, true],
        ];
    }

    #[DataProvider('providerStatuses')]
    public function test_it_maps_every_pixelforge_status(PixelforgeGenerationStatus $status): void
    {
        $this->assertSame($status->value, GenerationStatus::fromPixelforge($status)->value);
    }

    public static function providerStatuses(): array
    {
        return array_map(fn ($status) => [$status], PixelforgeGenerationStatus::cases());
    }

    public function test_ledger_entry_types_have_the_expected_values(): void
    {
        $this->assertSame(['purchase', 'hold', 'hold_release', 'charge'], array_column(LedgerEntryType::cases(), 'value'));
    }
}
