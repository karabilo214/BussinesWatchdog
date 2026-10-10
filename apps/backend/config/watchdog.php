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

    'browser' => [
        'lease_seconds' => 150,
        'heartbeat_seconds' => 15,
        'absolute_run_seconds' => 120,
        'result_grace_seconds' => 30,
        'retry_delay_seconds' => 60,
        'max_attempts' => 3,
        'manual_runs_per_minute' => 1,
        'schedule_jitter' => 0.1,
        'default_interval_seconds' => 900,
    ],

    'artifacts' => [
        'disk' => env('WATCHDOG_ARTIFACTS_DISK', 'artifacts'),
        'retention_days' => (int) env('WATCHDOG_ARTIFACTS_RETENTION_DAYS', 30),
        'max_bytes' => 2 * 1024 * 1024,
        'max_per_attempt' => 3,
        'download_ttl_seconds' => 60,
    ],

    'stripe' => [
        'api_base' => env('WATCHDOG_STRIPE_API_BASE', 'https://api.stripe.com'),
        'api_version' => '2026-09-30.endive',
        'timeout_seconds' => 20,
        'page_size' => 100,
        'max_pages_per_run' => 20,
        'delta_overlap_minutes' => 60,
        'audit_days' => 90,
        'webhook_tolerance_seconds' => 300,
    ],
];
