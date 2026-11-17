<?php

declare(strict_types=1);

namespace App\Enums;

use App\Pixelforge\PixelforgeGenerationStatus;

enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    public static function fromPixelforge(PixelforgeGenerationStatus $status): self
    {
        return match ($status) {
            PixelforgeGenerationStatus::Queued => self::Queued,
            PixelforgeGenerationStatus::Processing => self::Processing,
            PixelforgeGenerationStatus::Succeeded => self::Succeeded,
            PixelforgeGenerationStatus::Failed => self::Failed,
        };
    }
}
