<?php

declare(strict_types=1);

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pixelforge\Exceptions\PixelforgeRequestFailed;
use App\Pixelforge\Exceptions\PixelforgeUnreachable;
use App\Pixelforge\GenerationData;
use App\Pixelforge\PixelforgeGenerationStatus;
use App\Pricing\PricingTable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class PixelforgeClientTest extends PixelforgeTestCase
{
    public function test_create_sends_only_documented_parameters_as_json_with_authentication_and_idempotency(): void
    {
        $fixture = self::fixture('generation-queued');
        Http::fake([self::BASE_URL.'/generations' => Http::response($fixture, 202)]);
        $params = array_intersect_key($fixture, array_flip(['prompt', 'size', 'quality', 'reference_images', 'metadata']));

        $generation = $this->client()->createGeneration('test-idempotency-key', $params + ['status' => 'succeeded']);

        $this->assertInstanceOf(GenerationData::class, $generation);
        $this->assertSame(PixelforgeGenerationStatus::Queued, $generation->status);
        $this->assertSame($fixture['id'], $generation->id);
        $this->assertSame($fixture, $generation->raw);
        $this->assertFalse($generation->replayed);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE_URL.'/generations'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request->hasHeader('Idempotency-Key', 'test-idempotency-key')
            && $request->hasHeader('Content-Type', 'application/json')
            && $request->data() === $params);
        Http::assertSentCount(1);
    }

    public function test_create_leaves_optional_parameters_absent(): void
    {
        Http::fake([self::BASE_URL.'/generations' => Http::response(self::fixture('generation-queued'), 202)]);

        $this->client()->createGeneration('test-key', ['prompt' => 'A lighthouse']);

        Http::assertSent(fn (Request $request): bool => $request->data() === ['prompt' => 'A lighthouse']);
    }

    #[DataProvider('replayHeaders')]
    public function test_create_reads_the_idempotent_replayed_header(?string $header, bool $expected): void
    {
        Http::fake([self::BASE_URL.'/generations' => Http::response(
            self::fixture('generation-succeeded'),
            202,
            $header === null ? [] : ['Idempotent-Replayed' => $header],
        )]);

        $generation = $this->client()->createGeneration('test-key', ['prompt' => 'A lighthouse']);

        $this->assertSame($expected, $generation->replayed);
        $this->assertSame(PixelforgeGenerationStatus::Succeeded, $generation->status);
    }

    public static function replayHeaders(): array
    {
        return [[null, false], ['false', false], ['true', true]];
    }

    public function test_retrieve_parses_a_succeeded_generation(): void
    {
        $fixture = self::fixture('generation-succeeded');
        $url = self::BASE_URL.'/generations/'.$fixture['id'];
        Http::fake([$url => Http::response($fixture)]);

        $generation = $this->client()->retrieveGeneration($fixture['id']);

        $this->assertSame(PixelforgeGenerationStatus::Succeeded, $generation->status);
        $this->assertSame(12, $generation->usage->inputTextTokens);
        $this->assertSame(765, $generation->usage->inputImageTokens);
        $this->assertSame(6450, $generation->usage->outputTokens);
        $this->assertSame(3184, $generation->usage->reasoningTokens);
        $this->assertSame($fixture['rates'], $generation->rates->toArray());
        $this->assertSame(12_500, $generation->rates->reasoningTokens);
        $this->assertSame($fixture['image_url'], $generation->imageUrl);
        $this->assertInstanceOf(CarbonImmutable::class, $generation->imageUrlExpiresAt);
        $this->assertSame('2026-11-05T14:03:29Z', $generation->imageUrlExpiresAt->toIso8601ZuluString());
        $this->assertSame('2026-11-04T14:03:29Z', $generation->completedAt->toIso8601ZuluString());
        $this->assertFalse($generation->replayed);
        $this->assertNull($generation->errorCode);
        $this->assertNull($generation->errorMessage);
        Http::assertSent(fn (Request $request): bool => $request->url() === $url
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && ! $request->hasHeader('Idempotency-Key'));
    }

    public function test_retrieve_parses_a_failed_generation(): void
    {
        $fixture = self::fixture('generation-failed');
        Http::fake([self::BASE_URL.'/generations/'.$fixture['id'] => Http::response($fixture)]);

        $generation = $this->client()->retrieveGeneration($fixture['id']);

        $this->assertSame(PixelforgeGenerationStatus::Failed, $generation->status);
        $this->assertSame('internal_error', $generation->errorCode);
        $this->assertSame($fixture['error']['message'], $generation->errorMessage);
        $this->assertNull($generation->usage);
        $this->assertNull($generation->imageUrl);
    }

    public function test_pricing_payload_returns_the_raw_decoded_body_without_authentication(): void
    {
        $payload = self::fixture('pricing');
        Http::fake([self::BASE_URL.'/pricing' => Http::response($payload)]);

        $this->assertSame($payload, $this->client()->pricingPayload());
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE_URL.'/pricing'
            && $request->method() === 'GET'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_pricing_returns_the_pricing_table_without_authentication(): void
    {
        Http::fake([self::BASE_URL.'/pricing' => Http::response(self::fixture('pricing'))]);

        $table = $this->client()->pricing();

        $this->assertInstanceOf(PricingTable::class, $table);
        $this->assertSame(1100, $table->outputTokens('medium', '1024x1024'));
        $this->assertSame(765, $table->tokensPerReferenceImage);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE_URL.'/pricing'
            && $request->method() === 'GET'
            && ! $request->hasHeader('Authorization')
            && ! $request->hasHeader('Idempotency-Key'));
    }

    public function test_usage_sends_the_date_range_and_parses_exact_costs(): void
    {
        $url = self::BASE_URL.'/usage?start_date=2026-11-01&end_date=2026-11-02';
        Http::fake([$url => Http::response(self::fixture('usage'))]);

        $report = $this->client()->usage('2026-11-01', '2026-11-02');

        $this->assertSame('2026-11-01', $report->startDate);
        $this->assertSame('2026-11-02', $report->endDate);
        $this->assertCount(2, $report->days);
        $this->assertSame('2026-11-01', $report->days[0]->date);
        $this->assertSame(3, $report->days[0]->generations);
        $this->assertSame(41, $report->days[0]->inputTextTokens);
        $this->assertSame(0, $report->days[0]->inputImageTokens);
        $this->assertSame(3300, $report->days[0]->outputTokens);
        $this->assertSame(2950, $report->days[0]->reasoningTokens);
        $this->assertSame(169_080_000, $report->days[0]->costNanodollars);
        $this->assertSame(0, $report->days[1]->costNanodollars);
        $this->assertSame(0, $report->days[1]->generations);
        $this->assertNull($report->total->date);
        $this->assertSame(3, $report->total->generations);
        $this->assertSame(41, $report->total->inputTextTokens);
        $this->assertSame(0, $report->total->inputImageTokens);
        $this->assertSame(3300, $report->total->outputTokens);
        $this->assertSame(2950, $report->total->reasoningTokens);
        $this->assertSame(169_080_000, $report->total->costNanodollars);
        Http::assertSent(fn (Request $request): bool => $request->url() === $url
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    #[DataProvider('operations')]
    public function test_every_call_accepts_json_and_sets_both_timeouts(string $operation, string $fixture): void
    {
        Http::fake(function (Request $request, array $options) use ($fixture) {
            $this->assertTrue($request->hasHeader('Accept', 'application/json'));
            $this->assertSame(15, $options['timeout']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertFalse($options['allow_redirects']);

            return Http::response(self::fixture($fixture));
        });

        $this->callOperation($operation);

        Http::assertSentCount(1);
    }

    #[DataProvider('documentedErrors')]
    public function test_documented_http_errors_are_structured_and_never_retried(string $code, string $type, int $status, ?int $retryAfter, ?string $param = null): void
    {
        $fixture = self::fixture('error-'.str_replace('_', '-', $code));
        Http::fake(['*' => Http::response($fixture, $status, $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter])]);

        try {
            $this->client()->createGeneration('test-key', ['prompt' => 'A lighthouse']);
            $this->fail('Expected a Pixelforge request failure.');
        } catch (PixelforgeRequestFailed $exception) {
            $this->assertSame($status, $exception->status);
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($type, $exception->errorType);
            $this->assertSame($param, $exception->param);
            $this->assertSame($retryAfter, $exception->retryAfter);
            $this->assertSame($fixture['error']['message'], $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    public static function documentedErrors(): array
    {
        return [
            ['invalid_api_key', 'authentication_error', 401, null],
            ['validation_failed', 'invalid_request_error', 422, null, 'size'],
            ['content_policy_violation', 'invalid_request_error', 400, null],
            ['not_found', 'invalid_request_error', 404, null],
            ['idempotency_key_reused', 'idempotency_error', 409, null],
            ['rate_limit_exceeded', 'rate_limit_error', 429, 17],
            ['concurrency_limit_exceeded', 'rate_limit_error', 429, 5],
            ['daily_limit_exceeded', 'rate_limit_error', 429, null],
            ['overloaded', 'api_error', 503, 2],
            ['date_range_too_long', 'invalid_request_error', 422, null],
        ];
    }

    #[DataProvider('operationNames')]
    public function test_every_operation_wraps_connection_failures_without_retrying(string $operation): void
    {
        $attempts = 0;
        $previous = new ConnectionException('Connection timed out.');
        Http::fake(function () use (&$attempts, $previous) {
            $attempts++;
            throw $previous;
        });

        try {
            $this->callOperation($operation);
            $this->fail('Expected Pixelforge to be unreachable.');
        } catch (PixelforgeUnreachable $exception) {
            $this->assertSame($previous, $exception->getPrevious());
        }

        $this->assertSame(1, $attempts);
    }

    #[DataProvider('operationNames')]
    public function test_every_operation_rejects_non_success_responses(string $operation): void
    {
        Http::fake(['*' => Http::response('<html>Bad Gateway</html>', 502, ['Content-Type' => 'text/html'])]);

        try {
            $this->callOperation($operation);
            $this->fail('Expected a Pixelforge request failure.');
        } catch (PixelforgeRequestFailed $exception) {
            $this->assertSame(502, $exception->status);
            $this->assertSame('unknown', $exception->errorCode);
            $this->assertNull($exception->errorType);
            $this->assertNull($exception->param);
            $this->assertNull($exception->retryAfter);
        }

        Http::assertSentCount(1);
    }

    public function test_redirects_are_reported_as_non_success_responses(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => self::BASE_URL.'/pricing'])]);
        $this->expectException(PixelforgeRequestFailed::class);

        $this->client()->pricing();
    }

    #[DataProvider('unstructuredErrorBodies')]
    public function test_missing_error_details_have_safe_defaults(string $body): void
    {
        Http::fake(['*' => Http::response($body, 502)]);

        try {
            $this->client()->pricing();
            $this->fail('Expected a Pixelforge request failure.');
        } catch (PixelforgeRequestFailed $exception) {
            $this->assertSame('unknown', $exception->errorCode);
            $this->assertNull($exception->errorType);
            $this->assertNull($exception->param);
            $this->assertSame('Pixelforge request failed with HTTP status 502.', $exception->getMessage());
        }
    }

    public static function unstructuredErrorBodies(): array
    {
        return [[''], ['{}'], ['null'], ['"Bad Gateway"'], ['{"error":"Bad Gateway"}']];
    }

    #[DataProvider('retryAfterHeaders')]
    public function test_retry_after_is_an_optional_non_negative_integer(string $header, ?int $expected): void
    {
        Http::fake(['*' => Http::response(self::fixture('error-overloaded'), 503, ['Retry-After' => $header])]);

        try {
            $this->client()->pricing();
            $this->fail('Expected a Pixelforge request failure.');
        } catch (PixelforgeRequestFailed $exception) {
            $this->assertSame($expected, $exception->retryAfter);
        }
    }

    public static function retryAfterHeaders(): array
    {
        return [['0', 0], ['', null], ['invalid', null], ['-1', null]];
    }

    public static function operations(): array
    {
        return [
            ['create', 'generation-queued'],
            ['retrieve', 'generation-succeeded'],
            ['pricing', 'pricing'],
            ['usage', 'usage'],
        ];
    }

    public static function operationNames(): array
    {
        return array_map(fn (array $operation): array => [$operation[0]], self::operations());
    }

    private function callOperation(string $operation): mixed
    {
        return match ($operation) {
            'create' => $this->client()->createGeneration('test-key', ['prompt' => 'A lighthouse']),
            'retrieve' => $this->client()->retrieveGeneration('gen_test'),
            'pricing' => $this->client()->pricing(),
            'usage' => $this->client()->usage('2026-11-01', '2026-11-02'),
        };
    }

    #[DataProvider('nonObjectBodies')]
    public function test_successful_non_object_bodies_raise_a_payload_exception(string $operation, string $body): void
    {
        Http::fake(['*' => Http::response($body, 200)]);
        $this->expectException(InvalidPixelforgePayload::class);
        $this->expectExceptionMessage('JSON object');

        $this->callOperation($operation);
    }

    public static function nonObjectBodies(): array
    {
        $cases = [];
        foreach (['create', 'retrieve', 'pricing', 'usage'] as $operation) {
            foreach (['null', '[]', '[{}]', '42', '"bad"', '<html>Bad</html>'] as $body) {
                $cases[] = [$operation, $body];
            }
        }

        return $cases;
    }
}
