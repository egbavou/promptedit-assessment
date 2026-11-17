<?php

declare(strict_types=1);

namespace App\Pixelforge;

final readonly class WebhookSignature
{
    public function verify(string $header, string $rawBody, string $secret, int $now): bool
    {
        if ($secret === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $key = trim($key);
            $value = trim($value);
            if ($key === 't') {
                if ($timestamp !== null) {
                    return false;
                }
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || preg_match('/^-?\d+$/D', $timestamp) !== 1) {
            return false;
        }
        $seconds = filter_var($timestamp, FILTER_VALIDATE_INT);
        if ($seconds === false || abs($now - $seconds) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
