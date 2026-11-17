<?php

declare(strict_types=1);

namespace App\Pixelforge;

use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pricing\Rates;
use App\Pricing\TokenUsage;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

final readonly class GenerationData
{
    /**
     * @param  list<string>  $referenceImages
     * @param  array<string, string>  $metadata
     * @param  array<string, mixed>  $raw
     */
    private function __construct(
        public string $id,
        public PixelforgeGenerationStatus $status,
        public string $prompt,
        public string $size,
        public string $quality,
        public array $referenceImages,
        public array $metadata,
        public ?string $imageUrl,
        public ?CarbonImmutable $imageUrlExpiresAt,
        public ?TokenUsage $usage,
        public Rates $rates,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $startedAt,
        public ?CarbonImmutable $completedAt,
        public array $raw,
        public bool $replayed,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data, bool $replayed = false): self
    {
        foreach (['id', 'object', 'status', 'prompt', 'size', 'quality', 'reference_images', 'metadata', 'image_url', 'image_url_expires_at', 'usage', 'rates', 'error', 'created_at', 'started_at', 'completed_at'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new InvalidPixelforgePayload("The {$field} field is missing.");
            }
        }

        foreach (['id', 'object', 'status', 'prompt', 'size', 'quality'] as $field) {
            if (! is_string($data[$field])) {
                throw new InvalidPixelforgePayload("The {$field} field must be a string.");
            }
        }

        if ($data['object'] !== 'generation') {
            throw new InvalidPixelforgePayload('The object field must be generation.');
        }

        $status = PixelforgeGenerationStatus::tryFrom($data['status']);
        if ($status === null) {
            throw new InvalidPixelforgePayload('The status field is not a valid generation status.');
        }

        if (! is_array($data['reference_images']) || ! array_is_list($data['reference_images'])) {
            throw new InvalidPixelforgePayload('The reference_images field must be a list of strings.');
        }
        foreach ($data['reference_images'] as $value) {
            if (! is_string($value)) {
                throw new InvalidPixelforgePayload('The reference_images field must be a list of strings.');
            }
        }

        if (! is_array($data['metadata'])) {
            throw new InvalidPixelforgePayload('The metadata field must be a string-to-string object.');
        }
        foreach ($data['metadata'] as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new InvalidPixelforgePayload('The metadata field must be a string-to-string object.');
            }
        }

        if ($data['image_url'] !== null && ! is_string($data['image_url'])) {
            throw new InvalidPixelforgePayload('The image_url field must be a string or null.');
        }

        $usage = null;
        if ($data['usage'] !== null) {
            if (! is_array($data['usage']) || ! is_int($data['usage']['total_tokens'] ?? null) || $data['usage']['total_tokens'] < 0) {
                throw new InvalidPixelforgePayload('The usage field must be an object with non-negative integer token counts.');
            }
            try {
                $usage = TokenUsage::fromArray($data['usage']);
            } catch (InvalidArgumentException $exception) {
                throw new InvalidPixelforgePayload('Invalid usage: '.$exception->getMessage(), 0, $exception);
            }
        }

        if (! is_array($data['rates'])) {
            throw new InvalidPixelforgePayload('The rates field must be an object of decimal strings.');
        }
        try {
            $rates = Rates::fromArray($data['rates']);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidPixelforgePayload('Invalid rates: '.$exception->getMessage(), 0, $exception);
        }

        if ($data['error'] !== null) {
            if (! is_array($data['error']) || ! is_string($data['error']['message'] ?? null)) {
                throw new InvalidPixelforgePayload('The error field must be an object with string code and message fields, or null.');
            }
            $missingFailureCode = $status === PixelforgeGenerationStatus::Failed && ! array_key_exists('code', $data['error']);
            if (! $missingFailureCode && ! is_string($data['error']['code'] ?? null)) {
                throw new InvalidPixelforgePayload('The error code field must be a string.');
            }
        }

        return new self(
            id: $data['id'],
            status: $status,
            prompt: $data['prompt'],
            size: $data['size'],
            quality: $data['quality'],
            referenceImages: $data['reference_images'],
            metadata: $data['metadata'],
            imageUrl: $data['image_url'],
            imageUrlExpiresAt: self::timestamp($data['image_url_expires_at'], 'image_url_expires_at'),
            usage: $usage,
            rates: $rates,
            errorCode: $data['error']['code'] ?? null,
            errorMessage: $data['error']['message'] ?? null,
            createdAt: self::timestamp($data['created_at'], 'created_at', nullable: false),
            startedAt: self::timestamp($data['started_at'], 'started_at'),
            completedAt: self::timestamp($data['completed_at'], 'completed_at'),
            raw: $data,
            replayed: $replayed,
        );
    }

    private static function timestamp(mixed $value, string $field, bool $nullable = true): ?CarbonImmutable
    {
        if ($value === null && $nullable) {
            return null;
        }

        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw new InvalidPixelforgePayload("The {$field} field must be an ISO 8601 timestamp".($nullable ? ' or null.' : '.'));
        }

        try {
            $timestamp = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw new InvalidPixelforgePayload("The {$field} field must be a valid ISO 8601 timestamp.");
            }

            return CarbonImmutable::instance($timestamp)->setTimezone('UTC');
        } catch (Exception $exception) {
            throw new InvalidPixelforgePayload("The {$field} field must be a valid ISO 8601 timestamp.", 0, $exception);
        }
    }
}
