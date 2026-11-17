<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class DecimalString
{
    public static function toScaledInt(string $value, int $scale): int
    {
        if ($scale < 0 || preg_match('/\A[0-9]+(?:\.[0-9]+)?\z/', $value) !== 1) {
            throw new InvalidArgumentException('Expected an unsigned decimal string and a non-negative scale.');
        }

        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $fraction = rtrim($fraction, '0');

        if (strlen($fraction) > $scale) {
            throw new InvalidArgumentException('The decimal value cannot be represented exactly at this scale.');
        }

        $digits = ltrim($whole.$fraction, '0');

        if ($digits === '') {
            return 0;
        }

        $padding = $scale - strlen($fraction);
        $limit = (string) PHP_INT_MAX;

        // Check the length before padding, including for arbitrarily large scales.
        if ($padding > strlen($limit) - strlen($digits)) {
            throw new InvalidArgumentException('The scaled decimal value exceeds the integer range.');
        }

        $digits .= str_repeat('0', $padding);

        if (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0) {
            throw new InvalidArgumentException('The scaled decimal value exceeds the integer range.');
        }

        return (int) $digits;
    }
}
