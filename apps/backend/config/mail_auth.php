<?php

return [
    'dkim' => [
        'enabled' => env('MAIL_DKIM_ENABLED', false),
        'provider_managed' => env('MAIL_DKIM_PROVIDER_MANAGED', false),
        'domain' => env('MAIL_DKIM_DOMAIN'),
        'selector' => env('MAIL_DKIM_SELECTOR'),
        'private_key' => env('MAIL_DKIM_PRIVATE_KEY'),
        'private_key_path' => env('MAIL_DKIM_PRIVATE_KEY_PATH'),
        'passphrase' => env('MAIL_DKIM_PASSPHRASE', ''),
    ],
];
