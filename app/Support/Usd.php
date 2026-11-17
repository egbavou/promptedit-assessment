<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class Usd
{
    public static function format(int $nanodollars, int $decimals = 4): string
    {
        if ($decimals < 0) {
            throw new InvalidArgumentException('The number of decimal places must be non-negative.');
        }

        // Work on digits so PHP_INT_MIN never needs to be negated.
        $digits = str_pad(ltrim((string) $nanodollars, '-'), 10, '0', STR_PAD_LEFT);
        $whole = substr($digits, 0, -9);
        $fraction = substr($digits, -9);

        if ($decimals < 9 && trim(substr($fraction, $decimals), '0') !== '') {
            throw new InvalidArgumentException('The amount cannot be represented exactly at this precision.');
        }

        $prefix = $nanodollars < 0 ? '-$' : '$';

        if ($decimals === 0) {
            return $prefix.$whole;
        }

        return $prefix.$whole.'.'.str_pad(substr($fraction, 0, $decimals), $decimals, '0');
    }

    public static function display(int $nanodollars): string
    {
        return self::format($nanodollars, $nanodollars % 100_000 === 0 ? 4 : 9);
    }

    public static function markedUp(int $nanodollars): string
    {
        // Multiply the digits in two chunks to avoid overflowing cost * 125.
        $low = ($nanodollars % 1_000_000_000) * 125;
        $high = intdiv($nanodollars, 1_000_000_000) * 125 + intdiv($low, 1_000_000_000);
        $digits = str_pad((string) $high.str_pad((string) ($low % 1_000_000_000), 9, '0', STR_PAD_LEFT), 12, '0', STR_PAD_LEFT);
        $fraction = str_pad(rtrim(substr($digits, -11), '0'), 9, '0');

        return '$'.substr($digits, 0, -11).'.'.$fraction;
    }

    public static function exact(int $nanodollars): string
    {
        return self::format($nanodollars, 9);
    }
}
