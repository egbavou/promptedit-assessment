<?php

namespace Tests\Unit\Pricing;

use App\Pricing\PricingTable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class PricingTableTest extends PricingTestCase
{
    public function test_it_reads_the_documented_pricing_payload(): void
    {
        $table = self::table();

        $this->assertSame(self::rates(), $table->rates->toArray());
        $this->assertSame(765, $table->tokensPerReferenceImage);

        foreach (self::payload()['tokens']['output'] as $quality => $sizes) {
            foreach ($sizes as $size => $tokens) {
                $this->assertSame($tokens, $table->outputTokens($quality, $size));
            }
        }

        foreach (self::payload()['tokens']['reasoning'] as $quality => $bounds) {
            $this->assertSame($bounds['min'], $table->minReasoningTokens($quality));
            $this->assertSame($bounds['max'], $table->maxReasoningTokens($quality));
        }
    }

    #[DataProvider('unknownDimensions')]
    public function test_it_rejects_unknown_sizes_or_qualities(string $quality, string $size): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::table()->outputTokens($quality, $size);
    }

    public static function unknownDimensions(): array
    {
        return [['ultra', '1024x1024'], ['medium', '512x512']];
    }

    #[DataProvider('reasoningAccessors')]
    public function test_reasoning_accessors_reject_unknown_quality(string $method): void
    {
        $this->expectException(InvalidArgumentException::class);

        self::table()->{$method}('ultra');
    }

    public static function reasoningAccessors(): array
    {
        return [['minReasoningTokens'], ['maxReasoningTokens']];
    }

    public function test_values_come_from_the_payload(): void
    {
        $payload = self::payload();
        $payload['tokens']['per_reference_image'] = 100;
        $payload['tokens']['output']['low']['1024x1024'] = 300;
        $payload['tokens']['reasoning']['low'] = ['min' => 10, 'max' => 600];
        $table = PricingTable::fromApi($payload);

        $this->assertSame(100, $table->tokensPerReferenceImage);
        $this->assertSame(300, $table->outputTokens('low', '1024x1024'));
        $this->assertSame(10, $table->minReasoningTokens('low'));
        $this->assertSame(600, $table->maxReasoningTokens('low'));
    }

    public function test_it_rejects_an_inverted_reasoning_range(): void
    {
        $payload = self::payload();
        $payload['tokens']['reasoning']['low'] = ['min' => 501, 'max' => 500];
        $this->expectException(InvalidArgumentException::class);

        PricingTable::fromApi($payload);
    }
}
