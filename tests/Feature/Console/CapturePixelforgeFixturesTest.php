<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class CapturePixelforgeFixturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_run_outside_local_without_contacting_pixelforge(): void
    {
        Http::preventStrayRequests();

        $this->artisan('app:capture-pixelforge-fixtures')
            ->expectsOutputToContain('only available in the local environment')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_local_capture_uses_real_fixture_bodies_and_retains_only_safe_response_headers(): void
    {
        $this->app->instance('env', 'local');
        config()->set('services.pixelforge.key', 'capture-test-api-key');
        config()->set('services.pixelforge.webhook_secret', 'capture-test-webhook-secret');
        Sleep::fake();
        Http::preventStrayRequests();
        $sequence = Http::sequence();
        foreach (['pricing', 'create-succeed', 'retrieve-succeed', 'create-fail', 'retrieve-fail', 'error-blocked', 'error-overloaded', 'usage', 'list'] as $name) {
            $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/pixelforge/real-'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
            if ($name === 'create-succeed') {
                $fixture['body']['metadata'] = ['sensitive' => 'capture-test-api-key capture-test-webhook-secret'];
            }
            $sequence->push($fixture['body'], $fixture['status'], $fixture['headers'] + ['Authorization' => 'capture-test-api-key', 'Set-Cookie' => 'private-cookie']);
        }
        Http::fake(['*' => $sequence]);
        $written = [];
        File::shouldReceive('ensureDirectoryExists')->with(base_path('tests/Fixtures/pixelforge'));
        File::shouldReceive('put')->andReturnUsing(function (string $path, string $contents) use (&$written): int {
            $this->assertStringNotContainsString('capture-test-api-key', $contents);
            $this->assertStringNotContainsString('capture-test-webhook-secret', $contents);
            $this->assertStringNotContainsString('private-cookie', $contents);
            $written[basename($path)] = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

            return strlen($contents);
        });

        $this->artisan('app:capture-pixelforge-fixtures')->assertSuccessful();

        $this->assertCount(9, $written);
        $this->assertSame(202, $written['real-create-succeed.json']['status']);
        $this->assertSame('succeeded', $written['real-retrieve-succeed.json']['body']['status']);
        $this->assertSame('failed', $written['real-retrieve-fail.json']['body']['status']);
        $this->assertSame(400, $written['real-error-blocked.json']['status']);
        $this->assertSame(503, $written['real-error-overloaded.json']['status']);
        $this->assertSame('2', $written['real-error-overloaded.json']['headers']['Retry-After']);
        Http::assertSentCount(9);
    }
}
