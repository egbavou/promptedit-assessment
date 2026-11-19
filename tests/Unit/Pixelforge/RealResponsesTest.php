<?php

declare(strict_types=1);

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\Exceptions\PixelforgeRequestFailed;
use App\Pixelforge\GenerationData;
use App\Pixelforge\UsageReport;
use App\Pixelforge\UsageRepository;
use App\Pricing\PricingTable;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

class RealResponsesTest extends PixelforgeTestCase
{
    public function test_real_pricing_matches_the_documented_rates_and_token_tables(): void
    {
        $capture = self::real('pricing');
        $this->assertSame(200, $capture['status']);
        $this->assertSame(self::fixture('pricing'), $capture['body']);
        Http::fake(['*' => Http::response($capture['body'], $capture['status'], $capture['headers'])]);

        $this->assertEquals(PricingTable::fromApi($capture['body']), $this->client()->pricing());
    }

    #[DataProvider('generationFixtures')]
    public function test_real_generations_parse_through_the_existing_client(string $name, string $status): void
    {
        $capture = self::real($name);
        Http::fake(['*' => Http::response($capture['body'], $capture['status'], $capture['headers'])]);

        $data = str_starts_with($name, 'create-')
            ? $this->client()->createGeneration('real-fixture-replay', ['prompt' => $capture['body']['prompt']])
            : $this->client()->retrieveGeneration($capture['body']['id']);

        $this->assertSame($status, $data->status->value);
        $this->assertSame($capture['body'], $data->raw);
        $this->assertSame($capture['body']['rates'], $data->rates->toArray());
        $this->assertSame($capture['body']['error']['code'] ?? null, $data->errorCode);
        if ($status === 'succeeded') {
            $this->assertNotNull($data->usage);
            $this->assertSame($capture['body']['usage']['input_text_tokens'], $data->usage->inputTextTokens);
            $this->assertSame($capture['body']['usage']['input_image_tokens'], $data->usage->inputImageTokens);
            $this->assertSame($capture['body']['usage']['output_tokens'], $data->usage->outputTokens);
            $this->assertSame($capture['body']['usage']['reasoning_tokens'], $data->usage->reasoningTokens);
            $this->assertNotNull($data->imageUrl);
            $this->assertNotNull($data->completedAt);
        } else {
            $this->assertNull($data->usage);
        }
    }

    public static function generationFixtures(): array
    {
        return [['create-succeed', 'queued'], ['retrieve-succeed', 'succeeded'], ['create-fail', 'queued'], ['retrieve-fail', 'failed']];
    }

    #[DataProvider('errorFixtures')]
    public function test_real_errors_preserve_status_code_type_message_and_retry_after(string $name, int $status, string $code, ?int $retryAfter): void
    {
        $capture = self::real('error-'.$name);
        $this->assertSame($status, $capture['status']);
        Http::fake(['*' => Http::response($capture['body'], $capture['status'], $capture['headers'])]);

        try {
            $this->client()->createGeneration('real-fixture-error', ['prompt' => '['.$name.']']);
            $this->fail('Expected a provider refusal.');
        } catch (PixelforgeRequestFailed $exception) {
            $this->assertSame($status, $exception->status);
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($capture['body']['error']['type'], $exception->errorType);
            $this->assertSame($capture['body']['error']['message'], $exception->getMessage());
            $this->assertSame($retryAfter, $exception->retryAfter);
        }
    }

    public static function errorFixtures(): array
    {
        return [['blocked', 400, 'content_policy_violation', null], ['overloaded', 503, 'overloaded', 2]];
    }

    #[DataProvider('usageFixtures')]
    public function test_real_usage_preserves_exact_nanodollars_and_requested_days(string $name): void
    {
        $capture = self::real($name);
        $body = $capture['body'];
        Http::fake(['*' => Http::response($body, $capture['status'], $capture['headers'])]);

        $report = $this->client()->usage($body['start_date'], $body['end_date']);

        $this->assertEquals(UsageReport::fromArray($body), $report);
        $this->assertSame((int) str_replace('.', '', $body['total']['cost_usd']), $report->total->costNanodollars);
        $this->assertSame($body['total']['generations'], $report->total->generations);
        $this->assertEquals($report, app(UsageRepository::class)->forRange($body['start_date'], $body['end_date']));
    }

    public static function usageFixtures(): array
    {
        return [['usage'], ['usage-final']];
    }

    public function test_every_object_in_the_real_list_parses(): void
    {
        $capture = self::real('list');
        $this->assertSame(200, $capture['status']);
        $this->assertSame('list', $capture['body']['object']);
        $this->assertIsBool($capture['body']['has_more']);
        $this->assertNotEmpty($capture['body']['data']);
        foreach ($capture['body']['data'] as $object) {
            $this->assertSame($object, GenerationData::fromArray($object)->raw);
        }
    }

    private static function real(string $name): array
    {
        return json_decode(file_get_contents(__DIR__.'/../../Fixtures/pixelforge/real-'.$name.'.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
