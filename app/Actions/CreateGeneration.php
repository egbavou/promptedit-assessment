<?php

declare(strict_types=1);

namespace App\Actions;

use App\Billing\InsufficientCredits;
use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\PricingRepository;
use App\Pricing\PriceCalculator;
use Illuminate\Support\Facades\DB;

final readonly class CreateGeneration
{
    public function __construct(
        private PricingRepository $pricing,
        private PriceCalculator $calculator,
        private Ledger $ledger,
    ) {}

    /** @param array{prompt: string, size: string, quality: string, reference_images: list<string>} $validated */
    public function handle(User $user, array $validated): Generation
    {
        $pricing = $this->pricing->current();
        $estimate = $this->calculator->estimate(
            $validated['prompt'],
            $validated['size'],
            $validated['quality'],
            count($validated['reference_images']),
            $pricing,
        );

        return DB::transaction(function () use ($user, $validated, $estimate): Generation {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $available = $this->ledger->available($user);

            if ($available < $estimate->maxPrice) {
                throw new InsufficientCredits($available, $estimate->maxPrice);
            }

            $generation = $user->generations()->create([
                'status' => GenerationStatus::Pending,
                'prompt' => $validated['prompt'],
                'size' => $validated['size'],
                'quality' => $validated['quality'],
                'reference_images' => $validated['reference_images'],
                'estimated_min_nanodollars' => $estimate->minPrice,
                'estimated_max_nanodollars' => $estimate->maxPrice,
            ]);

            $this->ledger->hold($user, $generation, $estimate->maxPrice);
            SubmitGeneration::dispatch($generation->id)->afterCommit();

            return $generation;
        });
    }
}
