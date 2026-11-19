<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pixelforge\Exceptions\PixelforgeRequestFailed;
use App\Pixelforge\Exceptions\PixelforgeUnreachable;
use App\Pricing\PricingTable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use stdClass;

final readonly class PixelforgeClient
{
    public function __construct(
        private string $key,
        private string $baseUrl,
    ) {}

    /** @param array<string, mixed> $params */
    public function createGeneration(string $idempotencyKey, array $params): GenerationData
    {
        $params = array_intersect_key($params, array_flip(['prompt', 'size', 'quality', 'reference_images', 'metadata']));
        $response = $this->request('POST', '/generations', ['json' => $params], ['Idempotency-Key' => $idempotencyKey]);

        return GenerationData::fromArray($response->json(), $response->header('Idempotent-Replayed') === 'true');
    }

    public function retrieveGeneration(string $id): GenerationData
    {
        return GenerationData::fromArray($this->request('GET', '/generations/'.rawurlencode($id))->json());
    }

    public function pricing(): PricingTable
    {
        return PricingTable::fromApi($this->pricingPayload());
    }

    /** @return array<string, mixed> */
    public function pricingPayload(): array
    {
        $payload = $this->request('GET', '/pricing', authenticated: false)->json();

        return $payload;
    }

    public function usage(string $startDate, string $endDate): UsageReport
    {
        return UsageReport::fromArray($this->usagePayload($startDate, $endDate));
    }

    /** @return array<string, mixed> */
    public function usagePayload(string $startDate, string $endDate): array
    {
        return $this->request('GET', '/usage', ['query' => ['start_date' => $startDate, 'end_date' => $endDate]])->json();
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $headers
     */
    private function request(string $method, string $path, array $options = [], array $headers = [], bool $authenticated = true): Response
    {
        $response = $this->rawRequest($method, $path, $options, $headers, $authenticated);

        if (! $response->successful()) {
            throw PixelforgeRequestFailed::fromResponse($response);
        }

        if (! json_decode($response->body()) instanceof stdClass) {
            throw new InvalidPixelforgePayload('The Pixelforge response must be a JSON object.');
        }

        return $response;
    }

    /**
     * Capture provider responses before payload validation or error mapping.
     *
     * @param  array<string, mixed>  $options
     * @param  array<string, string>  $headers
     */
    public function rawRequest(string $method, string $path, array $options = [], array $headers = [], bool $authenticated = true): Response
    {
        $request = Http::timeout(15)
            ->connectTimeout(5)
            ->acceptJson()
            ->withoutRedirecting()
            ->withHeaders($headers);

        if ($authenticated) {
            $request = $request->withToken($this->key);
        }

        try {
            $response = $request->send($method, rtrim($this->baseUrl, '/').$path, $options);
        } catch (ConnectionException $exception) {
            throw new PixelforgeUnreachable($exception);
        }

        return $response;
    }
}
