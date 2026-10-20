<?php

return [
    'release' => env('WATCHDOG_RELEASE', 'dev'),

    'plugin_dir' => env('WATCHDOG_PLUGIN_DIR', storage_path('app/plugins')),
    'connector_release_gate' => (bool) env('WATCHDOG_CONNECTOR_RELEASE_GATE', env('APP_ENV') === 'production'),
    'woo_approved_sha256' => (string) env('WATCHDOG_WOO_APPROVED_SHA256', ''),

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

    'reconciliation' => [
        'lookback_days' => (int) env('WATCHDOG_RECONCILIATION_LOOKBACK_DAYS', 90),
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

    'exports' => [
        'disk' => env('WATCHDOG_EXPORTS_DISK', env('WATCHDOG_ARTIFACTS_DISK', 'artifacts')),
        'retention_days' => (int) env('WATCHDOG_EXPORTS_RETENTION_DAYS', 7),
        'max_rows' => (int) env('WATCHDOG_EXPORTS_MAX_ROWS', 10000),
    ],

    'stripe' => [
        'api_base' => env('WATCHDOG_STRIPE_API_BASE', 'https://api.stripe.com'),
        'api_version' => '2026-09-30.endive',
        'timeout_seconds' => 20,
        'page_size' => 100,
        'max_pages_per_run' => 20,
        'delta_overlap_minutes' => 60,
        'audit_days' => (int) env('WATCHDOG_STRIPE_AUDIT_DAYS', 90),
        'webhook_tolerance_seconds' => 300,
    ],

    'paypal' => [
        'api_base_live' => env('WATCHDOG_PAYPAL_API_BASE_LIVE', 'https://api-m.paypal.com'),
        'api_base_sandbox' => env('WATCHDOG_PAYPAL_API_BASE_SANDBOX', 'https://api-m.sandbox.paypal.com'),
        'timeout_seconds' => 20,
        'search_page_size' => 500,
        'max_search_pages_per_run' => 10,
        'max_orders_per_run' => 300,
        'search_window_days' => 31,
        'delta_overlap_minutes' => 180,
        'audit_days' => (int) env('WATCHDOG_PAYPAL_AUDIT_DAYS', 90),
        'webhook_tolerance_seconds' => 600,
        'pointer_window_hours' => 6,
        'pointer_max_per_run' => 50,
        'pointer_retry_minutes' => 15,
    ],

    'woocommerce_api' => [
        'timeout_seconds' => 20,
        'page_size' => 100,
        'max_requests_per_run' => 60,
        'request_pause_ms' => 200,
        'max_response_bytes' => 5242880,
        'delta_overlap_minutes' => 10,
        'recent_hours' => 48,
        'audit_days' => (int) env('WATCHDOG_WOOCOMMERCE_API_AUDIT_DAYS', 90),
        'stale_after_minutes' => 30,
        'dev_endpoint' => env('WATCHDOG_DEV_WC_API_ENDPOINT', ''),
    ],

    'billing' => [
        'enforcement' => env('WATCHDOG_BILLING_ENFORCEMENT', 'pilot'),
        'past_due_grace_days' => 7,
        'checkout_expires_hours' => 24,
        'event_tolerance_seconds' => 300,
        'event_max_attempts' => 10,
        'paddle' => [
            'environment' => env('PADDLE_ENVIRONMENT', 'sandbox'),
            'api_key' => env('PADDLE_API_KEY', env('APP_ENV') === 'production' ? null : env('WATCHDOG_DEV_PADDLE_API_KEY')),
            'client_token' => env('PADDLE_CLIENT_TOKEN', env('APP_ENV') === 'production' ? null : env('WATCHDOG_DEV_PADDLE_CLIENT_TOKEN')),
            'webhook_secret' => env('PADDLE_WEBHOOK_SECRET', env('APP_ENV') === 'production' ? null : env('WATCHDOG_DEV_PADDLE_WEBHOOK_SECRET')),
            'account_ref' => env('PADDLE_ACCOUNT_REF', 'default'),
            'api_base_live' => 'https://api.paddle.com',
            'api_base_sandbox' => 'https://sandbox-api.paddle.com',
            'api_version' => '1',
            'timeout_seconds' => 20,
            'events_page_size' => 200,
            'events_max_pages_per_run' => 10,
        ],
    ],

    'platform' => [
        'session_cookie' => env('WATCHDOG_PLATFORM_SESSION_COOKIE', 'bw-platform-session'),
        'csrf_cookie' => env('WATCHDOG_PLATFORM_CSRF_COOKIE', 'BW-PLATFORM-XSRF'),
        'idle_minutes' => (int) env('WATCHDOG_PLATFORM_IDLE_MINUTES', 30),
        'absolute_hours' => (int) env('WATCHDOG_PLATFORM_ABSOLUTE_HOURS', 8),
        'login_per_minute' => (int) env('WATCHDOG_PLATFORM_LOGIN_PER_MINUTE', 5),
        'mfa_pending_seconds' => 300,
        'mfa_max_attempts' => 5,
        'audit_retention_days' => (int) env('WATCHDOG_PLATFORM_AUDIT_RETENTION_DAYS', 730),
    ],

    'site' => [
        'contact_notify' => env('WATCHDOG_SITE_CONTACT_NOTIFY', ''),
        'contact_retention_days' => (int) env('WATCHDOG_SITE_CONTACT_RETENTION_DAYS', 365),
        'contact_per_hour' => (int) env('WATCHDOG_SITE_CONTACT_PER_HOUR', 5),
        'owner_url' => env('WATCHDOG_SITE_OWNER_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/owner'),
        'cache_seconds' => (int) env('WATCHDOG_SITE_CACHE_SECONDS', 60),
        'media_disk' => env('WATCHDOG_SITE_MEDIA_DISK', env('WATCHDOG_ARTIFACTS_DISK', 'artifacts')),
        'media_types' => array_values(array_filter(array_map('trim', explode(',', strtolower((string) env('WATCHDOG_SITE_MEDIA_TYPES', 'jpeg,png,webp')))))),
        'media_max_bytes' => (int) env('WATCHDOG_SITE_MEDIA_MAX_BYTES', 5 * 1024 * 1024),
        'media_max_side' => (int) env('WATCHDOG_SITE_MEDIA_MAX_SIDE', 6000),
        'media_max_pixels' => (int) env('WATCHDOG_SITE_MEDIA_MAX_PIXELS', 16_000_000),
        'media_resize_max_side' => (int) env('WATCHDOG_SITE_MEDIA_RESIZE_MAX_SIDE', 2560),
        'media_jpeg_quality' => (int) env('WATCHDOG_SITE_MEDIA_JPEG_QUALITY', 82),
        'media_webp_quality' => (int) env('WATCHDOG_SITE_MEDIA_WEBP_QUALITY', 82),
        'media_png_compression' => (int) env('WATCHDOG_SITE_MEDIA_PNG_COMPRESSION', 9),
    ],

    'service_alerts' => [
        'email' => env('WATCHDOG_SERVICE_ALERT_EMAIL', ''),
        'locale' => env('WATCHDOG_SERVICE_ALERT_LOCALE', 'ru'),
    ],

    'public_check' => [
        'captcha_ttl_minutes' => (int) env('WATCHDOG_PUBLIC_CHECK_CAPTCHA_TTL_MINUTES', 10),
        'verify_dns' => (bool) env('WATCHDOG_PUBLIC_CHECK_VERIFY_DNS', true),
        'bypass_limits_on_localhost' => (bool) env('WATCHDOG_PUBLIC_CHECK_BYPASS_LIMITS_ON_LOCALHOST', env('APP_ENV') === 'local'),
        'prefer_public_leases' => (bool) env('WATCHDOG_PUBLIC_CHECK_PREFER_PUBLIC_LEASES', env('APP_ENV') === 'local'),
        'submit_attempts_per_hour' => (int) env('WATCHDOG_PUBLIC_CHECK_SUBMIT_ATTEMPTS_PER_HOUR', 5),
        'host_cooldown_days' => (int) env('WATCHDOG_PUBLIC_CHECK_HOST_COOLDOWN_DAYS', 30),
        'ip_cooldown_days' => (int) env('WATCHDOG_PUBLIC_CHECK_IP_COOLDOWN_DAYS', 10),
        'artifact_ttl_minutes' => (int) env('WATCHDOG_PUBLIC_CHECK_ARTIFACT_TTL_MINUTES', 5),
        'history_retention_days' => (int) env('WATCHDOG_PUBLIC_CHECK_HISTORY_RETENTION_DAYS', 90),
        'queue_wait_seconds' => (int) env('WATCHDOG_PUBLIC_CHECK_QUEUE_WAIT_SECONDS', 120),
        'lease_seconds' => (int) env('WATCHDOG_PUBLIC_CHECK_LEASE_SECONDS', 150),
        'absolute_run_seconds' => (int) env('WATCHDOG_PUBLIC_CHECK_ABSOLUTE_RUN_SECONDS', 120),
        'result_grace_seconds' => (int) env('WATCHDOG_PUBLIC_CHECK_RESULT_GRACE_SECONDS', 90),
        'max_running' => (int) env('WATCHDOG_PUBLIC_CHECK_MAX_RUNNING', 1),
        'max_artifacts' => (int) env('WATCHDOG_PUBLIC_CHECK_MAX_ARTIFACTS', 3),
        'max_artifact_bytes' => (int) env('WATCHDOG_PUBLIC_CHECK_MAX_ARTIFACT_BYTES', 2 * 1024 * 1024),
    ],

    'shopware' => [
        'app_approved_sha256' => (string) env('WATCHDOG_SHOPWARE_APP_APPROVED_SHA256', ''),
        'app_approved_store_ids' => (string) env('WATCHDOG_SHOPWARE_APP_APPROVED_STORE_IDS', ''),
        'timeout_seconds' => 20,
        'page_size' => 100,
        'max_requests_per_run' => 60,
        'request_pause_ms' => 200,
        'max_response_bytes' => 5242880,
        'delta_overlap_minutes' => 10,
        'audit_days' => (int) env('WATCHDOG_SHOPWARE_AUDIT_DAYS', 90),
        'dev_endpoint' => env('WATCHDOG_DEV_SHOPWARE_ENDPOINT', ''),
    ],
];
