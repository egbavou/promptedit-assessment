<?php

declare(strict_types=1);

namespace App\Pixelforge\Exceptions;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

final class PixelforgeUnreachable extends RuntimeException
{
    public function __construct(ConnectionException $previous)
    {
        parent::__construct('Could not reach Pixelforge.', 0, $previous);
    }
}
