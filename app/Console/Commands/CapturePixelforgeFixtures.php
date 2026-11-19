<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CapturePixelforgeFixtures extends Command
{
    protected $signature = 'app:capture-pixelforge-fixtures';

    protected $description = 'Capture real Pixelforge response fixtures (local only; creates two generations)';

    public function handle(PixelforgeClient $client): int
    {
        if (! app()->environment('local')) {
            $this->error('This command is only available in the local environment.');

            return self::FAILURE;
        }

        try {
            $this->capture('pricing', $client->rawRequest('GET', '/pricing', authenticated: false));
            foreach (['succeed' => 'succeeded', 'fail' => 'failed'] as $keyword => $finalStatus) {
                // Follow the application's three-concurrent gate, with a bounded wait.
                for ($wait = 0; $wait < 36; $wait++) {
                    if (Generation::query()->whereIn('status', ['queued', 'processing'])->where('accepted_at', '>=', now()->subHour())->count() < 3) {
                        break;
                    }
                    Sleep::sleep(5);
                }
                if ($wait === 36) {
                    throw new RuntimeException('The local concurrency gate did not open.');
                }
                $key = (string) Str::uuid();
                for ($attempt = 1; $attempt <= 4; $attempt++) {
                    Sleep::sleep(5);
                    $response = $client->rawRequest('POST', '/generations', ['json' => [
                        'prompt' => "Fixture capture: a lighthouse [{$keyword}]",
                        'size' => '1024x1024', 'quality' => 'low',
                    ]], ['Idempotency-Key' => $key]);
                    $this->capture('create-'.$keyword.($attempt > 1 ? '-attempt-'.$attempt : ''), $response);
                    if ($response->status() === 202) {
                        // Keep the canonical fixture as the accepted response after a transient refusal.
                        $this->capture('create-'.$keyword, $response);
                        break;
                    }
                    if (! in_array($response->status(), [429, 503], true) || $attempt === 4) {
                        throw new RuntimeException('Generation creation was refused.');
                    }
                    Sleep::sleep(max(5, (int) $response->header('Retry-After')));
                }
                $id = $response->json('id');
                if (! is_string($id)) {
                    throw new RuntimeException('The accepted response has no generation id.');
                }
                for ($poll = 0; $poll < 24; $poll++) {
                    Sleep::sleep(10);
                    $response = $client->rawRequest('GET', '/generations/'.rawurlencode($id));
                    $this->capture('retrieve-'.$keyword, $response);
                    if ($response->successful() && $response->json('status') === $finalStatus) {
                        break;
                    }
                    if (in_array($response->status(), [429, 503], true)) {
                        Sleep::sleep(max(10, (int) $response->header('Retry-After')));
                    }
                }
                if ($poll === 24) {
                    throw new RuntimeException('Generation did not reach the expected final status.');
                }
            }
            foreach (['blocked', 'overloaded'] as $keyword) {
                Sleep::sleep(5);
                $this->capture('error-'.$keyword, $client->rawRequest('POST', '/generations', [
                    'json' => ['prompt' => "Fixture capture [{$keyword}]"],
                ], ['Idempotency-Key' => (string) Str::uuid()]));
            }
            $today = now('UTC')->toDateString();
            $this->capture('usage', $client->rawRequest('GET', '/usage', ['query' => ['start_date' => $today, 'end_date' => $today]]));
            $this->capture('list', $client->rawRequest('GET', '/generations', ['query' => ['limit' => 10]]));
        } catch (Throwable $exception) {
            // Do not render HTTP exceptions, which can contain authenticated requests.
            $this->error('Capture stopped ('.get_class($exception).'). Check the captured status/body before retrying.');

            return self::FAILURE;
        }

        $this->info('Captured real Pixelforge fixtures in tests/Fixtures/pixelforge.');

        return self::SUCCESS;
    }

    private function capture(string $name, Response $response): void
    {
        $headers = [];
        foreach (['Content-Type', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Idempotent-Replayed'] as $header) {
            if ($response->header($header) !== '') {
                $headers[$header] = $response->header($header);
            }
        }
        $json = json_encode([
            'status' => $response->status(),
            'headers' => $headers,
            'body' => json_decode($response->body(), flags: JSON_THROW_ON_ERROR),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        foreach (['key', 'webhook_secret'] as $setting) {
            $secret = config('services.pixelforge.'.$setting);
            if (is_string($secret) && $secret !== '') {
                $json = str_replace($secret, '[REDACTED]', $json);
            }
        }
        $directory = base_path('tests/Fixtures/pixelforge');
        File::ensureDirectoryExists($directory);
        File::put($directory.'/real-'.$name.'.json', $json);
        $this->line('real-'.$name.'.json: HTTP '.$response->status());
    }
}
