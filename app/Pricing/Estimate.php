<?php

declare(strict_types=1);

namespace App\Pricing;

final readonly class Estimate
{
    public function __construct(
        public int $minProviderCost,
        public int $maxProviderCost,
        public int $minPrice,
        public int $maxPrice,
        public TokenUsage $minTokens,
        public TokenUsage $maxTokens,
    ) {}
}
