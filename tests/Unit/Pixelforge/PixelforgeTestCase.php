<?php

declare(strict_types=1);

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

abstract class PixelforgeTestCase extends TestCase
{
    protected const BASE_URL = 'https://pixelforge.example.test/v1';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('services.pixelforge', [
            'key' => 'test-api-key',
            'webhook_secret' => 'test-webhook-secret',
            'base_url' => self::BASE_URL.'/',
        ]);
    }

    protected function client(): PixelforgeClient
    {
        return app(PixelforgeClient::class);
    }

    protected static function fixture(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../Fixtures/pixelforge/doc-'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
