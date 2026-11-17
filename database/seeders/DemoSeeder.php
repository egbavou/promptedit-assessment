<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Billing\Ledger;
use App\Enums\FailureReason;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        DB::transaction(function (): void {
            $user = User::query()->firstOrCreate(['email' => 'demo@example.com'], [
                'name' => 'Demo Artist',
                'email_verified_at' => now(),
                'password' => Hash::make(config('demo.password')),
            ]);
            $ledger = app(Ledger::class);
            $ledger->purchase($user, '26e0fc85-b82a-4f78-80c7-e5feb8d14445', 5_000_000_000);

            foreach (GenerationStatus::cases() as $index => $status) {
                $succeeded = $status === GenerationStatus::Succeeded;
                $generation = Generation::query()->firstOrCreate([
                    'id' => sprintf('26e0fc85-b82a-4f78-80c7-e5feb8d1444%d', $index),
                ], [
                    'user_id' => $user->id,
                    'status' => $status,
                    'prompt' => $succeeded ? 'A lighthouse on a cliff at dawn, oil painting' : str_pad('A quiet coastal village at sunrise, painted in warm pastel colors.', 100, ' '),
                    'size' => $succeeded ? '1536x1024' : '1024x1024',
                    'quality' => $succeeded ? 'high' : 'medium',
                    'reference_images' => $succeeded ? [url('/images/demo-generation.svg')] : [],
                    'estimated_min_nanodollars' => $succeeded ? 347_800_000 : 58_300_000,
                    'estimated_max_nanodollars' => $succeeded ? 457_200_000 : 86_500_000,
                    'pixelforge_id' => $status === GenerationStatus::Pending ? null : 'gen_demo_'.$status->value,
                    'rates' => ['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'],
                    'usage' => $succeeded ? ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411] : null,
                    'provider_cost_nanodollars' => $succeeded ? 305_510_000 : null,
                    'price_nanodollars' => $succeeded ? 381_900_000 : null,
                    'error_code' => $status === GenerationStatus::Failed ? FailureReason::InternalError->value : null,
                    'error_message' => $status === GenerationStatus::Failed ? FailureReason::InternalError->message() : null,
                    'image_url' => $succeeded ? url('/images/demo-generation.svg') : null,
                    'image_url_expires_at' => $succeeded ? now()->addYears(10) : null,
                    'accepted_at' => $status === GenerationStatus::Pending ? null : now()->subMinutes(5),
                    'started_at' => $status->rank() >= 2 ? now()->subMinutes(4) : null,
                    'completed_at' => $status->isFinal() ? now()->subMinutes(3) : null,
                    'created_at' => now()->subMinutes(6 + $index),
                ]);
                $ledger->hold($user, $generation, $generation->estimated_max_nanodollars);
                if ($status->isFinal()) {
                    $ledger->releaseHold($generation);
                }
                if ($succeeded) {
                    $ledger->charge($generation, $generation->price_nanodollars ?? 0);
                }
            }
        });

        if (! Cache::has('pixelforge.pricing.last_known')) {
            Cache::forever('pixelforge.pricing.last_known', File::json(base_path('tests/Fixtures/pixelforge/doc-pricing.json')));
        }
    }
}
