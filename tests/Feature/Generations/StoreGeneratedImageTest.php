<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Enums\GenerationStatus;
use App\Jobs\StoreGeneratedImage;
use App\Models\Generation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Unit\Pixelforge\PixelforgeTestCase;

class StoreGeneratedImageTest extends PixelforgeTestCase
{
    use RefreshDatabase;

    private const PNG = "\x89PNG\r\n\x1a\nimage bytes";

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        config()->set('filesystems.images_disk', 'images');
        Storage::fake('images');
        Http::fake([]);
    }

    #[DataProvider('imageDisks')]
    public function test_it_stores_the_png_and_saves_its_path_on_the_configured_disk(string $disk): void
    {
        config()->set('filesystems.images_disk', $disk);
        Storage::fake($disk);
        $generation = $this->generation();
        Http::fake(function ($request, array $options) {
            $this->assertSame(30, $options['timeout']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(self::PNG, 200, ['Content-Type' => 'image/png']);
        });

        (new StoreGeneratedImage($generation->id))->handle();

        $path = 'generations/'.$generation->id.'.png';
        $this->assertSame($path, $generation->refresh()->image_path);
        $this->assertSame(self::PNG, Storage::disk($disk)->get($path));
        $this->assertSame('private', Storage::disk($disk)->getVisibility($path));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === $generation->image_url && $request->method() === 'GET');
    }

    public static function imageDisks(): array
    {
        return [['images'], ['cloud-images']];
    }

    public function test_an_expired_url_is_not_downloaded_and_logs_a_warning(): void
    {
        $generation = $this->generation(['image_url_expires_at' => now()->subSecond()]);
        Log::spy();

        (new StoreGeneratedImage($generation->id))->handle();

        $this->assertNothingStored($generation);
        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['generation_id'] === $generation->id);
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_urls_are_rejected_without_a_request(string $url): void
    {
        $generation = $this->generation(['image_url' => $url]);
        Log::spy();

        (new StoreGeneratedImage($generation->id))->handle();

        $this->assertNothingStored($generation);
        Http::assertNothingSent();
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['generation_id'] === $generation->id);
    }

    public static function unsafeUrls(): array
    {
        return [
            ['https://foreign.example.test/image.png'],
            ['http://pixelforge.example.test/image.png'],
            ['https://pixelforge.example.test.evil.test/image.png'],
            ['/image.png'],
        ];
    }

    #[DataProvider('invalidResponses')]
    public function test_invalid_responses_throw_for_queue_retries(int $status, string $contentType, string $body): void
    {
        $generation = $this->generation();
        Http::fake([$generation->image_url => Http::response($body, $status, ['Content-Type' => $contentType])]);

        $exception = null;
        try {
            (new StoreGeneratedImage($generation->id))->handle();
        } catch (RuntimeException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertNothingStored($generation);
        Http::assertSentCount(1);
    }

    public static function invalidResponses(): array
    {
        return [
            'server error' => [500, 'image/png', self::PNG],
            'redirect' => [302, 'image/png', self::PNG],
            'HTML' => [200, 'text/html', '<html>error</html>'],
            'missing content type' => [200, '', self::PNG],
            'empty body' => [200, 'image/png', ''],
            'over 20 MiB' => [200, 'image/png', str_repeat('x', 20 * 1024 * 1024 + 1)],
        ];
    }

    #[DataProvider('skippedGenerations')]
    public function test_ineligible_generations_are_not_downloaded(array $changes): void
    {
        $generation = $this->generation($changes);

        (new StoreGeneratedImage($generation->id))->handle();

        Http::assertNothingSent();
        $this->assertSame($changes['image_path'] ?? null, $generation->refresh()->image_path);
        $this->assertSame([], Storage::disk('images')->allFiles());
    }

    public static function skippedGenerations(): array
    {
        return [
            [['image_path' => 'generations/already-stored.png']],
            [['status' => GenerationStatus::Pending]],
            [['status' => GenerationStatus::Queued]],
            [['status' => GenerationStatus::Processing]],
            [['status' => GenerationStatus::Failed]],
            [['image_url' => null]],
        ];
    }

    public function test_a_missing_generation_is_a_no_op(): void
    {
        (new StoreGeneratedImage('00000000-0000-4000-8000-000000000001'))->handle();

        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('images')->allFiles());
    }

    public function test_it_removes_the_file_if_saving_the_image_path_fails(): void
    {
        $generation = $this->generation();
        Http::fake([$generation->image_url => Http::response(self::PNG, 200, ['Content-Type' => 'image/png'])]);
        Generation::saving(function (Generation $model): void {
            if ($model->isDirty('image_path')) {
                throw new RuntimeException('Cannot save the image path.');
            }
        });

        try {
            (new StoreGeneratedImage($generation->id))->handle();
            $this->fail('The persistence failure must be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot save the image path.', $exception->getMessage());
        }

        $this->assertNothingStored($generation);
    }

    public function test_the_retry_policy_and_failure_callback_preserve_the_provider_fallback(): void
    {
        $generation = $this->generation();
        $job = new StoreGeneratedImage($generation->id);
        $exception = new RuntimeException('Retries exhausted.');
        Log::spy();

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 30], $job->backoff());
        $this->assertSame(60, $job->timeout);
        $job->failed($exception);

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context['generation_id'] === $generation->id && $context['exception'] === $exception);
        $this->assertNothingStored($generation);
        $this->assertSame($generation->image_url, $generation->display_image_url);
    }

    private function generation(array $changes = []): Generation
    {
        return Generation::factory()->succeeded()->create(array_replace([
            'image_url' => 'https://pixelforge.example.test/images/generated.png?signature=test',
            'image_url_expires_at' => now()->addDay(),
        ], $changes));
    }

    private function assertNothingStored(Generation $generation): void
    {
        $this->assertNull($generation->refresh()->image_path);
        $this->assertSame([], Storage::disk('images')->allFiles());
    }
}
