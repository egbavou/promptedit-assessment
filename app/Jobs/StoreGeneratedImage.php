<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class StoreGeneratedImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $generationId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(): void
    {
        $generation = Generation::query()->find($this->generationId);
        if ($generation === null || $generation->status !== GenerationStatus::Succeeded
            || $generation->image_path !== null || $generation->image_url === null) {
            return;
        }

        if ($generation->image_url_expires_at?->isPast()) {
            Log::warning('Cannot store an expired Pixelforge image.', ['generation_id' => $this->generationId]);

            return;
        }

        $url = $generation->image_url;
        $host = parse_url($url, PHP_URL_HOST);
        $providerHost = parse_url(config('services.pixelforge.base_url'), PHP_URL_HOST);
        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
            || ! is_string($host) || ! is_string($providerHost)
            || strcasecmp($host, $providerHost) !== 0) {
            Log::error('Refused an unsafe Pixelforge image URL.', ['generation_id' => $this->generationId]);

            return;
        }

        $response = Http::timeout(30)->connectTimeout(5)->withoutRedirecting()->get($url);
        if (! $response->successful()) {
            throw new RuntimeException('Pixelforge image download returned HTTP '.$response->status().'.');
        }

        $contentType = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        $body = $response->body();
        if ($contentType !== 'image/png' || $body === '' || strlen($body) > 20 * 1024 * 1024) {
            throw new RuntimeException('Pixelforge image download returned an invalid PNG response.');
        }

        // Serialize duplicate jobs before writing the deterministic file path.
        DB::transaction(function () use ($body): void {
            $generation = Generation::query()->lockForUpdate()->find($this->generationId);
            if ($generation === null || $generation->status !== GenerationStatus::Succeeded || $generation->image_path !== null) {
                return;
            }

            $disk = Storage::disk(config('filesystems.images_disk'));
            $path = 'generations/'.$generation->id.'.png';
            if (! $disk->put($path, $body, ['visibility' => 'private'])) {
                throw new RuntimeException('Could not store the generated image.');
            }

            try {
                $generation->image_path = $path;
                if (! $generation->save()) {
                    throw new RuntimeException('Could not save the generated image path.');
                }
            } catch (Throwable $exception) {
                $disk->delete($path);

                throw $exception;
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Failed to store a generated image.', [
            'generation_id' => $this->generationId,
            'exception' => $exception,
        ]);
    }
}
