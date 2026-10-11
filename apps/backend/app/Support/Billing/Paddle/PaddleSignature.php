<?php

namespace App\Support\Billing\Paddle;

use Illuminate\Support\Carbon;

/** `Paddle-Signature: ts=…;h1=…`: HMAC-SHA256 of `ts:raw body` with the notification destination's secret. */
final class PaddleSignature
{
    public static function valid(string $header, string $rawBody, string $secret, ?Carbon $now = null): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(';', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 'ts' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'h1' && $value !== '') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(($now ?? Carbon::now())->getTimestamp() - $timestamp) > (int) config('watchdog.billing.event_tolerance_seconds')) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.':'.$rawBody, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
