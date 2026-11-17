<?php

declare(strict_types=1);

namespace Tests\Feature\Generations;

use App\Models\Generation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GenerationImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        Http::preventStrayRequests();
        Http::fake();
        config()->set('filesystems.images_disk', 'images');
        Storage::fake('images');
    }

    public function test_the_accessor_prefers_the_private_route_and_is_only_appended_explicitly(): void
    {
        $generation = Generation::factory()->succeeded()->create([
            'image_path' => 'generations/image.png',
            'image_url' => 'https://pixelforge.example.test/image.png',
            'image_url_expires_at' => now()->subDay(),
        ]);

        $this->assertSame(route('generations.image', $generation), $generation->display_image_url);
        $this->assertArrayNotHasKey('display_image_url', $generation->toArray());
        $this->assertSame(route('generations.image', $generation), $generation->append('display_image_url')->toArray()['display_image_url']);
    }

    #[DataProvider('providerUrlExpirations')]
    public function test_the_accessor_only_returns_an_unexpired_provider_url(?int $seconds, bool $valid): void
    {
        $generation = Generation::factory()->succeeded()->create([
            'image_url' => 'https://pixelforge.example.test/image.png',
            'image_url_expires_at' => $seconds === null ? null : now()->addSeconds($seconds),
        ]);

        $this->assertSame($valid ? $generation->image_url : null, $generation->display_image_url);
    }

    public static function providerUrlExpirations(): array
    {
        return [[1, true], [-1, false], [0, false], [null, false]];
    }

    public function test_the_accessor_returns_null_without_any_image(): void
    {
        $this->assertNull(Generation::factory()->create()->display_image_url);
    }

    #[DataProvider('imageDisks')]
    public function test_the_owner_can_stream_the_exact_png_from_the_configured_disk(string $disk): void
    {
        config()->set('filesystems.images_disk', $disk);
        Storage::fake($disk);
        $generation = Generation::factory()->succeeded()->create(['image_path' => 'generations/image.png']);
        $bytes = "\x89PNG\r\n\x1a\nprivate image bytes";
        Storage::disk($disk)->put($generation->image_path, $bytes);

        $response = $this->actingAs($generation->user)->get(route('generations.image', $generation));

        $response->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('Cache-Control', 'max-age=86400, private');
        $this->assertSame($bytes, $response->streamedContent());
        Http::assertNothingSent();
    }

    public static function imageDisks(): array
    {
        return [['images'], ['cloud-images']];
    }

    public function test_another_user_is_forbidden_even_when_the_file_is_missing(): void
    {
        $generation = Generation::factory()->succeeded()->create(['image_path' => 'generations/image.png']);

        $this->actingAs(User::factory()->create())->get(route('generations.image', $generation))->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $generation = Generation::factory()->succeeded()->create();

        $this->get(route('generations.image', $generation))->assertRedirect(route('login'));
    }

    public function test_an_unverified_user_is_redirected_to_verification(): void
    {
        $generation = Generation::factory()->for(User::factory()->unverified())->succeeded()->create();

        $this->actingAs($generation->user)->get(route('generations.image', $generation))->assertRedirect(route('verification.notice'));
    }

    #[DataProvider('missingCopies')]
    public function test_the_owner_gets_a_404_without_a_stored_copy(?string $path): void
    {
        $generation = Generation::factory()->succeeded()->create(['image_path' => $path]);

        $this->actingAs($generation->user)->get(route('generations.image', $generation))->assertNotFound();
        Http::assertNothingSent();
    }

    public static function missingCopies(): array
    {
        return [[null], ['generations/missing.png']];
    }
}
