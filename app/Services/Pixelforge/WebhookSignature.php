<?php

declare(strict_types=1);

namespace App\Services\Pixelforge;

/**
 * Verifies the `Pixelforge-Signature` header: `t=<unix>,v1=<hex>[,v1=<hex>]`, where
 * v1 is HMAC-SHA256 of `<t>.<raw body>` (docs/adr/0008-webhook-verification-and-dedup.md).
 */
final class WebhookSignature
{
    public static function isValid(
        string $header,
        string $payload,
        string $secret,
        int $toleranceSeconds,
        int $now,
    ): bool {
        if ($secret === '') {
            return false;
        }

        $timestamp = null;
        $candidates = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            $key = trim($pair[0]);
            $value = trim($pair[1]);

            if ($key === 't') {
                if ($timestamp !== null) {
                    return false;
                }

                $timestamp = $value;
            } elseif ($key === 'v1') {
                $candidates[] = $value;
            }
        }

        if ($timestamp === null || $candidates === [] || preg_match('/^\d{1,15}$/', $timestamp) !== 1) {
            return false;
        }

        if (abs($now - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($candidates as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
