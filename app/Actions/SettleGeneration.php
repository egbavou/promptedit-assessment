<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pricing\PriceCalculator;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;
use Illuminate\Support\Facades\Log;

final readonly class SettleGeneration
{
    public function __construct(private PriceCalculator $calculator, private Ledger $ledger) {}

    public function succeed(Generation $generation, TokenUsage $usage, Rates $rates): void
    {
        $cost = $this->calculator->providerCost($usage, $rates);
        $price = $this->calculator->userPrice($cost);

        if ($price > $generation->estimated_max_nanodollars) {
            Log::warning('The actual generation price exceeds the reserved estimate.', [
                'generation_id' => $generation->id,
                'price_nanodollars' => $price,
                'estimated_max_nanodollars' => $generation->estimated_max_nanodollars,
            ]);
        }

        $generation->status = GenerationStatus::Succeeded;
        $generation->provider_cost_nanodollars = $cost;
        $generation->price_nanodollars = $price;
        $generation->usage = [
            'input_text_tokens' => $usage->inputTextTokens,
            'input_image_tokens' => $usage->inputImageTokens,
            'output_tokens' => $usage->outputTokens,
            'reasoning_tokens' => $usage->reasoningTokens,
            'total_tokens' => $usage->inputTextTokens + $usage->inputImageTokens + $usage->outputTokens + $usage->reasoningTokens,
        ];
        $generation->save();

        $this->ledger->releaseHold($generation);
        $this->ledger->charge($generation, $price);
    }

    public function fail(Generation $generation, string $errorCode, string $errorMessage): void
    {
        $generation->status = GenerationStatus::Failed;
        $generation->error_code = $errorCode;
        $generation->error_message = $errorMessage;
        $generation->save();

        $this->ledger->releaseHold($generation);
    }
}
