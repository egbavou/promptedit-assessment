<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Generation> */
class GenerationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => fake()->sentence(),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => [],
            'estimated_min_nanodollars' => 58_300_000,
            'estimated_max_nanodollars' => 86_500_000,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => GenerationStatus::Pending]);
    }

    public function queued(): static
    {
        return $this->state(fn (): array => ['status' => GenerationStatus::Queued]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => ['status' => GenerationStatus::Processing]);
    }

    public function succeeded(): static
    {
        return $this->state(fn (): array => ['status' => GenerationStatus::Succeeded]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => ['status' => GenerationStatus::Failed]);
    }
}
