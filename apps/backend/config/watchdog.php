<?php

return [
    'rate_limits' => [
        'login_per_minute' => (int) env('WATCHDOG_RATE_LOGIN_PER_MINUTE', 5),
        'signup_per_hour' => (int) env('WATCHDOG_RATE_SIGNUP_PER_HOUR', 3),
    ],
];
