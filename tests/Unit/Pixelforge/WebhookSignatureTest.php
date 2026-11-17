<?php

declare(strict_types=1);

namespace Tests\Unit\Pixelforge;

use App\Pixelforge\WebhookSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const string SECRET = 'whsec_unit_test';

    private const int NOW = 1_794_912_000;

    private const string BODY = '{"id":"evt_test","type":"ping"}';

    #[DataProvider('timestampOffsets')]
    public function test_timestamp_tolerance_is_inclusive_in_both_directions(int $offset, bool $valid): void
    {
        $timestamp = self::NOW + $offset;

        $this->assertSame($valid, (new WebhookSignature)->verify($this->header((string) $timestamp), self::BODY, self::SECRET, self::NOW));
    }

    public static function timestampOffsets(): array
    {
        return [[0, true], [-300, true], [300, true], [-301, false], [301, false]];
    }

    public function test_reencoding_the_same_json_with_different_whitespace_invalidates_the_signature(): void
    {
        $this->assertFalse((new WebhookSignature)->verify($this->header(), '{ "id": "evt_test", "type": "ping" }', self::SECRET, self::NOW));
    }

    public function test_spaces_and_multiple_signatures_are_supported_in_any_order(): void
    {
        $signature = hash_hmac('sha256', self::NOW.'.'.self::BODY, self::SECRET);
        $header = ' v1 = invalid , t = '.self::NOW.' , v1 = '.$signature.' , v1 = another-invalid ';

        $this->assertTrue((new WebhookSignature)->verify($header, self::BODY, self::SECRET, self::NOW));
    }

    #[DataProvider('invalidHeaders')]
    public function test_malformed_or_incomplete_headers_are_rejected(string $header): void
    {
        $this->assertFalse((new WebhookSignature)->verify($header, self::BODY, self::SECRET, self::NOW));
    }

    public static function invalidHeaders(): array
    {
        return [
            [''], ['t='.self::NOW], ['v1=abc'], ['t=,v1=abc'], ['t=abc,v1=abc'],
            ['t='.self::NOW.'.0,v1=abc'], ['t=1e9,v1=abc'], ['t=9999999999999999999999999999,v1=abc'],
            ['t='.self::NOW.',v1='], ['t='.self::NOW.',v1='.str_repeat('0', 64)],
            ['t='.self::NOW.',t='.self::NOW.',v1=abc'],
        ];
    }

    #[DataProvider('nonIntegerTimestamps')]
    public function test_a_correctly_signed_non_integer_timestamp_is_rejected(string $timestamp): void
    {
        $this->assertFalse((new WebhookSignature)->verify($this->header($timestamp), self::BODY, self::SECRET, self::NOW));
    }

    public static function nonIntegerTimestamps(): array
    {
        return [[self::NOW.'.0'], [(string) self::NOW.'junk'], ['1.794912e9']];
    }

    public function test_an_empty_secret_is_rejected_even_when_the_signature_matches(): void
    {
        $header = 't='.self::NOW.',v1='.hash_hmac('sha256', self::NOW.'.'.self::BODY, '');

        $this->assertFalse((new WebhookSignature)->verify($header, self::BODY, '', self::NOW));
    }

    private function header(?string $timestamp = null): string
    {
        $timestamp ??= (string) self::NOW;

        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.self::BODY, self::SECRET);
    }
}
