<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class CloudHttpsTest extends TestCase
{
    public function test_cloud_proxy_headers_produce_https_image_and_valid_signed_urls(): void
    {
        $original = $_SERVER['LARAVEL_CLOUD'] ?? null;
        $_SERVER['LARAVEL_CLOUD'] = '1';

        try {
            Route::get('/cloud-url-check', fn (Request $request): array => [
                'image' => route('generations.image', '00000000-0000-4000-8000-000000000001'),
                'signed' => URL::signedRoute('cloud-url-check'),
                'valid' => $request->hasValidSignature(),
            ])->name('cloud-url-check');
            Route::getRoutes()->refreshNameLookups();

            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '443']);

            $response = $this->get('http://assessment.laravel.cloud/cloud-url-check')->assertOk();
            $response->assertJsonPath('image', 'https://assessment.laravel.cloud/generations/00000000-0000-4000-8000-000000000001/image');
            $signed = $response->json('signed');
            $this->assertStringStartsWith('https://assessment.laravel.cloud/cloud-url-check?signature=', $signed);
            $this->get(str_replace('https://', 'http://', $signed))->assertOk()->assertJsonPath('valid', true);
        } finally {
            if ($original === null) {
                unset($_SERVER['LARAVEL_CLOUD']);
            } else {
                $_SERVER['LARAVEL_CLOUD'] = $original;
            }
            TrustProxies::flushState();
            Request::setTrustedProxies([], -1);
        }
    }
}
