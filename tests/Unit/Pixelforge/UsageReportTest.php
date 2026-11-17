<?php

declare(strict_types=1);

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\Exceptions\InvalidPixelforgePayload;
use App\Pixelforge\UsageDay;
use App\Pixelforge\UsageReport;
use PHPUnit\Framework\Attributes\DataProvider;

class UsageReportTest extends PixelforgeTestCase
{
    public function test_report_totals_are_read_from_the_response_without_recalculation(): void
    {
        $fixture = self::fixture('usage');
        $fixture['total']['cost_usd'] = '0.000000001';
        $fixture['total']['generations'] = 1;
        $report = UsageReport::fromArray($fixture);

        $this->assertSame(169_080_000, $report->days[0]->costNanodollars);
        $this->assertSame(1, $report->total->costNanodollars);
        $this->assertSame(1, $report->total->generations);
        $this->assertNull($report->total->date);
    }

    #[DataProvider('invalidReportFields')]
    public function test_malformed_reports_raise_a_payload_exception(string $field, mixed $value): void
    {
        $fixture = self::fixture('usage');
        $fixture[$field] = $value;
        $this->expectException(InvalidPixelforgePayload::class);
        UsageReport::fromArray($fixture);
    }

    public static function invalidReportFields(): array
    {
        return [
            ['object', 'generation'], ['start_date', null], ['start_date', '2026-02-30'],
            ['start_date', '2026-1-01'], ['end_date', 123], ['end_date', 'tomorrow'],
            ['data', null], ['data', 'invalid'], ['data', ['named' => []]],
            ['data', [null]], ['data', [[]]], ['total', null], ['total', []],
        ];
    }

    #[DataProvider('invalidDayFields')]
    public function test_malformed_days_raise_a_payload_exception(string $field, mixed $value): void
    {
        $day = self::fixture('usage')['data'][0];
        $day[$field] = $value;
        $this->expectException(InvalidPixelforgePayload::class);
        UsageDay::fromArray($day);
    }

    public static function invalidDayFields(): array
    {
        return [
            ['date', null], ['date', 1], ['date', '2026-02-30'], ['date', '2026-11-1'],
            ['generations', -1], ['generations', '3'], ['generations', true],
            ['input_text_tokens', -1], ['input_image_tokens', '0'],
            ['output_tokens', null], ['reasoning_tokens', 0.5],
            ['cost_usd', 1], ['cost_usd', null], ['cost_usd', '-0.000000001'],
            ['cost_usd', '1e-9'], ['cost_usd', '0.0000000001'], ['cost_usd', '0.1'],
            ['cost_usd', '999999999999999999999.000000000'],
        ];
    }

    public function test_missing_day_fields_are_rejected(): void
    {
        $day = self::fixture('usage')['data'][0];
        unset($day['generations']);
        $this->expectException(InvalidPixelforgePayload::class);
        UsageDay::fromArray($day);
    }

    public function test_daily_rows_require_a_date(): void
    {
        $fixture = self::fixture('usage');
        unset($fixture['data'][0]['date']);
        $this->expectException(InvalidPixelforgePayload::class);
        UsageReport::fromArray($fixture);
    }
}
