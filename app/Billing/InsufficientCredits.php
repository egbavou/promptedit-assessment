<?php

declare(strict_types=1);

namespace App\Billing;

use RuntimeException;

final class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $available, public readonly int $required)
    {
        parent::__construct('There are not enough available credits for this generation.');
    }
}
