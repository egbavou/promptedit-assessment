<?php

declare(strict_types=1);

namespace App\Pixelforge\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

final class PixelforgeRequestFailed extends RuntimeException
{
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly string $errorCode,
        public readonly ?string $errorType,
        public readonly ?string $param,
        public readonly ?int $retryAfter,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $body = $response->json();
        $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $retryAfter = filter_var($response->header('Retry-After'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return new self(
            message: is_string($error['message'] ?? null) ? $error['message'] : "Pixelforge request failed with HTTP status {$response->status()}.",
            status: $response->status(),
            errorCode: is_string($error['code'] ?? null) ? $error['code'] : 'unknown',
            errorType: is_string($error['type'] ?? null) ? $error['type'] : null,
            param: is_string($error['param'] ?? null) ? $error['param'] : null,
            retryAfter: $retryAfter === false ? null : $retryAfter,
        );
    }
}
