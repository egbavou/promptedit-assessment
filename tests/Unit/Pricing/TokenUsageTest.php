<?php

namespace Tests\Unit\Pricing;

use App\Pricing\TokenUsage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TokenUsageTest extends TestCase
{
    public function test_it_reads_the_four_counts_and_ignores_total_tokens(): void
    {
        $usage = TokenUsage::fromArray([
            'input_text_tokens' => 25,
            'input_image_tokens' => 765,
            'output_tokens' => 1100,
            'reasoning_tokens' => 1234,
            'total_tokens' => 0,
        ]);

        $this->assertSame(25, $usage->inputTextTokens);
        $this->assertSame(765, $usage->inputImageTokens);
        $this->assertSame(1100, $usage->outputTokens);
        $this->assertSame(1234, $usage->reasoningTokens);
    }

    #[DataProvider('invalidCounts')]
    public function test_counts_must_be_non_negative_integers(mixed $count): void
    {
        $this->expectException(InvalidArgumentException::class);

        TokenUsage::fromArray([
            'input_text_tokens' => $count,
            'input_image_tokens' => 0,
            'output_tokens' => 0,
            'reasoning_tokens' => 0,
        ]);
    }

    public static function invalidCounts(): array
    {
        return [[-1], ['25'], [null], [true]];
    }

    public function test_it_rejects_missing_counts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TokenUsage::fromArray([]);
    }

    public function test_direct_construction_rejects_negative_counts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TokenUsage(0, 0, -1, 0);
    }
}
