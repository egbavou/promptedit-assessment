<?php

declare(strict_types=1);

namespace App\Pixelforge\Exceptions;

use RuntimeException;
use Throwable;

final class PricingUnavailable extends RuntimeException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Pricing is temporarily unavailable. Nothing was charged; please try again in a minute.', 0, $previous);
    }
}
