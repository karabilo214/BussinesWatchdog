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

    'credentials' => [
        'draining_hours' => (int) env('WATCHDOG_CREDENTIAL_DRAINING_HOURS', 24),
    ],
];
