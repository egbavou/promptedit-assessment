<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Billing\InsufficientCredits;
use App\Billing\Ledger;
use App\Http\Requests\EstimateGenerationRequest;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Pixelforge\Exceptions\PricingUnavailable;
use App\Pixelforge\PricingRepository;
use App\Pricing\GenerationBreakdown;
use App\Pricing\PriceCalculator;
use App\Support\Usd;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class GenerationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection($request->user()->generations()->latest()->orderByDesc('id')->simplePaginate(20)),
            'options' => [
                'sizes' => ['1024x1024', '1536x1024', '1024x1536'],
                'qualities' => ['low', 'medium', 'high'],
                'max_prompt_length' => 4000,
                'max_reference_images' => 4,
            ],
        ]);
    }

    public function show(Request $request, Generation $generation, GenerationBreakdown $breakdown): Response
    {
        Gate::authorize('view', $generation);

        return Inertia::render('Generations/Show', [
            'generation' => (new GenerationResource($generation))->resolve($request),
            'breakdown' => $breakdown->forGeneration($generation),
        ]);
    }

    public function image(Generation $generation): StreamedResponse
    {
        Gate::authorize('view', $generation);

        abort_if($generation->image_path === null, 404);
        $disk = Storage::disk(config('filesystems.images_disk'));
        abort_unless($disk->exists($generation->image_path), 404);

        return $disk->response($generation->image_path, headers: [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function store(StoreGenerationRequest $request, CreateGeneration $create): RedirectResponse
    {
        /** @var array{prompt: string, size: string, quality: string, reference_images: list<string>} $validated */
        $validated = $request->validated();

        try {
            $create->handle($request->user(), $validated);
        } catch (InsufficientCredits $exception) {
            $available = Usd::display($exception->available);

            return back()->withErrors([
                'balance' => 'Not enough credits: this generation can cost up to '.Usd::format($exception->required)." and you have {$available} available.",
            ])->withInput();
        } catch (PricingUnavailable $exception) {
            return back()->withErrors(['pricing' => $exception->getMessage()])->withInput();
        }

        return to_route('generations.index')->with('success', 'Generation created.');
    }

    public function estimate(EstimateGenerationRequest $request, PricingRepository $pricing, PriceCalculator $calculator, Ledger $ledger): JsonResponse
    {
        /** @var array{prompt?: string|null, size: string, quality: string, reference_images: list<string>} $validated */
        $validated = $request->validated();

        try {
            $estimate = $calculator->estimate(
                $validated['prompt'] ?? '',
                $validated['size'],
                $validated['quality'],
                count($validated['reference_images']),
                $pricing->current(),
            );
        } catch (PricingUnavailable $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        $available = $ledger->available($request->user());

        return response()->json([
            'estimate' => Usd::format($estimate->minPrice).' – '.Usd::format($estimate->maxPrice),
            'min_nanodollars' => $estimate->minPrice,
            'max_nanodollars' => $estimate->maxPrice,
            'min_price' => substr(Usd::format($estimate->minPrice), 1),
            'max_price' => substr(Usd::format($estimate->maxPrice), 1),
            'available_nanodollars' => $available,
            'can_afford' => $available >= $estimate->maxPrice,
        ]);
    }
}
