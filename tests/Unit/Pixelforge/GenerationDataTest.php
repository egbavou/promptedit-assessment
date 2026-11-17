<?php

declare(strict_types=1);

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pixelforge\GenerationData;
use App\Pixelforge\PixelforgeGenerationStatus;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

class GenerationDataTest extends PixelforgeTestCase
{
    public function test_queued_fields_are_preserved_and_unavailable_fields_are_null(): void
    {
        $fixture = self::fixture('generation-queued');

        $generation = GenerationData::fromArray($fixture);

        $this->assertSame($fixture['id'], $generation->id);
        $this->assertSame($fixture['prompt'], $generation->prompt);
        $this->assertSame($fixture['size'], $generation->size);
        $this->assertSame($fixture['quality'], $generation->quality);
        $this->assertSame($fixture['reference_images'], $generation->referenceImages);
        $this->assertSame($fixture['metadata'], $generation->metadata);
        $this->assertSame($fixture, $generation->raw);
        $this->assertNull($generation->usage);
        $this->assertNull($generation->imageUrl);
        $this->assertNull($generation->imageUrlExpiresAt);
        $this->assertNull($generation->startedAt);
        $this->assertNull($generation->completedAt);
        $this->assertNull($generation->errorCode);
        $this->assertNull($generation->errorMessage);
        $this->assertInstanceOf(CarbonImmutable::class, $generation->createdAt);
        $this->assertSame('2026-11-04T14:03:11Z', $generation->createdAt->toIso8601ZuluString());
        $this->assertFalse($generation->replayed);
    }

    public function test_all_timestamps_are_normalized_to_utc_without_changing_the_raw_payload(): void
    {
        $fixture = self::fixture('generation-succeeded');
        foreach (['created_at', 'started_at', 'completed_at', 'image_url_expires_at'] as $key) {
            $fixture[$key] = '2026-11-04T16:03:29+02:00';
        }
        $fixture['status'] = 'processing';
        $fixture['metadata'] = [];
        $fixture['reference_images'] = [];

        $generation = GenerationData::fromArray($fixture, true);

        foreach ([$generation->createdAt, $generation->startedAt, $generation->completedAt, $generation->imageUrlExpiresAt] as $timestamp) {
            $this->assertInstanceOf(CarbonImmutable::class, $timestamp);
            $this->assertSame('UTC', $timestamp->timezoneName);
            $this->assertSame('2026-11-04T14:03:29Z', $timestamp->toIso8601ZuluString());
        }
        $this->assertSame(PixelforgeGenerationStatus::Processing, $generation->status);
        $this->assertSame([], $generation->metadata);
        $this->assertSame([], $generation->referenceImages);
        $this->assertSame($fixture, $generation->raw);
        $this->assertTrue($generation->replayed);
    }

    #[DataProvider('malformedFields')]
    public function test_malformed_fields_raise_a_payload_exception(string $field, mixed $value): void
    {
        $payload = self::fixture('generation-succeeded');
        data_set($payload, $field, $value);
        $this->expectException(InvalidPixelforgePayload::class);
        $this->expectExceptionMessage(explode('.', $field)[0]);

        GenerationData::fromArray($payload);
    }

    public static function malformedFields(): array
    {
        return [
            ['id', 42], ['object', 'list'], ['status', 'unknown'], ['prompt', null],
            ['size', []], ['quality', true], ['reference_images', 'url'],
            ['reference_images', ['url' => 'https://example.test/a.png']], ['reference_images', [42]],
            ['metadata', 'bad'], ['metadata', ['user_id' => 42]], ['metadata', ['user']],
            ['image_url', 42], ['usage', 'bad'], ['usage.output_tokens', '6450'],
            ['usage.total_tokens', '10411'], ['usage.total_tokens', -1],
            ['rates', null], ['rates.output_tokens', 40], ['rates.output_tokens', 'bad'],
            ['error', 'bad'], ['error', ['code' => 42, 'message' => 'Bad']],
            ['error', ['code' => 'internal_error']],
            ['created_at', null], ['started_at', 42], ['completed_at', 'tomorrow'],
            ['completed_at', '2026-02-30T14:03:29Z'], ['image_url_expires_at', 'bad'],
        ];
    }

    public function test_missing_fields_raise_a_payload_exception(): void
    {
        $payload = self::fixture('generation-queued');
        unset($payload['image_url']);
        $this->expectException(InvalidPixelforgePayload::class);
        $this->expectExceptionMessage('image_url');

        GenerationData::fromArray($payload);
    }
}
