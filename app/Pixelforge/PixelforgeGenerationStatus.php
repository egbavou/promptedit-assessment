<?php

declare(strict_types=1);

namespace App\Pixelforge;

enum PixelforgeGenerationStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
