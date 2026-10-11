<?php

namespace App\Support\Billing\Paddle;

final class PaddleConfig
{
    public const PROVIDER = 'paddle';

    public static function configured(): bool
    {
        return self::apiKey() !== '';
    }

    public static function sandbox(): bool
    {
        return config('watchdog.billing.paddle.environment') !== 'production';
    }

    /** Our mode for prices, contracts and events: Paddle's sandbox is `test`, production is `live`. */
    public static function mode(): string
    {
        return self::sandbox() ? 'test' : 'live';
    }

    public static function environment(): string
    {
        return self::sandbox() ? 'sandbox' : 'production';
    }

    public static function accountRef(): string
    {
        return (string) config('watchdog.billing.paddle.account_ref');
    }

    public static function apiBase(): string
    {
        return (string) config(self::sandbox() ? 'watchdog.billing.paddle.api_base_sandbox' : 'watchdog.billing.paddle.api_base_live');
    }

    public static function apiKey(): string
    {
        return (string) config('watchdog.billing.paddle.api_key');
    }

    public static function clientToken(): ?string
    {
        $token = (string) config('watchdog.billing.paddle.client_token');

        return $token === '' ? null : $token;
    }

    public static function webhookSecret(): ?string
    {
        $secret = (string) config('watchdog.billing.paddle.webhook_secret');

        return $secret === '' ? null : $secret;
    }
}
