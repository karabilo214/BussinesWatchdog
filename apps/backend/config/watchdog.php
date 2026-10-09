<?php

return [
    'rate_limits' => [
        'login_per_minute' => (int) env('WATCHDOG_RATE_LOGIN_PER_MINUTE', 5),
        'signup_per_hour' => (int) env('WATCHDOG_RATE_SIGNUP_PER_HOUR', 3),
        'pairing_per_hour' => (int) env('WATCHDOG_RATE_PAIRING_PER_HOUR', 20),
    ],

    'keyring' => [
        'current' => (int) env('WATCHDOG_KEYRING_CURRENT', 1),
        'v1_key' => env('WATCHDOG_KEYRING_V1'),
        'additional_keys' => env('WATCHDOG_KEYRING_KEYS', ''),
    ],

    'store_verification' => [
        'plugin_ttl_minutes' => (int) env('WATCHDOG_VERIFICATION_PLUGIN_TTL_MINUTES', 30),
        'dns_ttl_hours' => (int) env('WATCHDOG_VERIFICATION_DNS_TTL_HOURS', 24),
        'dns_record_prefix' => '_bw-verify',
        'recheck_seconds' => 60,
        'challenge_paths' => [
            'woocommerce' => '/wp-json/business-watchdog/v1/challenge/{id}',
            'default' => '/.well-known/business-watchdog/challenge/{id}',
        ],
    ],

    'credentials' => [
        'draining_hours' => (int) env('WATCHDOG_CREDENTIAL_DRAINING_HOURS', 24),
    ],
];
