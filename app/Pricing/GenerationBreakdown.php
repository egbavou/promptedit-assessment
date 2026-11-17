<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\DecimalString;
use App\Support\Usd;

final class GenerationBreakdown
{
    /** @return array{lines: list<array{label: string, tokens: int, rate: string, cost: string}>, provider_cost: string, marked_up: string, price: string}|null */
    public function forGeneration(Generation $generation): ?array
    {
        if ($generation->status !== GenerationStatus::Succeeded) {
            return null;
        }

        $lines = [];
        foreach ([
            'input_text_tokens' => 'Input text',
            'input_image_tokens' => 'Reference images',
            'output_tokens' => 'Output',
            'reasoning_tokens' => 'Reasoning',
        ] as $type => $label) {
            $tokens = ($generation->usage ?? [])[$type] ?? 0;
            $rate = ($generation->rates ?? [])[$type] ?? '0.00';
            $lines[] = [
                'label' => $label,
                'tokens' => $tokens,
                'rate' => '$'.$rate.' / 1M tokens',
                'cost' => Usd::exact($tokens * DecimalString::toScaledInt($rate, 3)),
            ];
        }

        return [
            'lines' => $lines,
            'provider_cost' => Usd::exact($generation->provider_cost_nanodollars ?? 0),
            'marked_up' => Usd::markedUp($generation->provider_cost_nanodollars ?? 0),
            'price' => Usd::format($generation->price_nanodollars ?? 0),
        ];
    }
}
