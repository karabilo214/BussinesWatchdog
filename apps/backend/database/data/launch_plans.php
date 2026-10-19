<?php

use App\Support\Billing\FeatureCatalog;

$core = [FeatureCatalog::CHECKOUT_MONITORING, FeatureCatalog::PAYMENT_ATTEMPTS_SERVER, FeatureCatalog::MONEY_RECONCILIATION, FeatureCatalog::NOTIFICATIONS_TELEGRAM];

return [
    'start' => [
        'name' => ['en' => 'Start', 'de' => 'Start', 'ru' => 'Start'],
        'description' => [
            'en' => 'For a small store with up to 1,000 orders a month: checkout checked in a browser every hour, payment attempts watched, money reconciled with the payment provider.',
            'de' => 'Für einen kleinen Shop mit bis zu 1.000 Bestellungen im Monat: stündliche Checkout-Prüfung im Browser, Überwachung der Zahlungsversuche, Abgleich des Geldes mit dem Zahlungsanbieter.',
            'ru' => 'Для небольшого магазина до 1 000 заказов в месяц: проверка оформления заказа в браузере каждый час, наблюдение за попытками оплаты, сверка денег с платёжным провайдером.',
        ],
        'trial_days' => 14,
        'grace_days' => 14,
        'features' => $core,
        'limits' => [
            FeatureCatalog::MAX_ACTIVE_STORES => 1,
            FeatureCatalog::MAX_EXTRA_STORES => 2,
            FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 60,
            FeatureCatalog::MANUAL_RUNS_PER_DAY => 5,
            FeatureCatalog::MONTHLY_EVENT_BUDGET => 5000,
            FeatureCatalog::RETENTION_DAYS => 90,
            FeatureCatalog::MAX_ORDERS_PER_MONTH => 1000,
        ],
        'prices' => ['base' => '1900', 'extra_store' => '900'],
    ],
    'business' => [
        'name' => ['en' => 'Business', 'de' => 'Business', 'ru' => 'Business'],
        'description' => [
            'en' => 'For a growing store with up to 5,000 orders a month: checks every 30 minutes, the observation script on the payment page and two stores included.',
            'de' => 'Für einen wachsenden Shop mit bis zu 5.000 Bestellungen im Monat: Prüfungen alle 30 Minuten, das Beobachtungsskript auf der Zahlungsseite und zwei Shops inklusive.',
            'ru' => 'Для растущего магазина до 5 000 заказов в месяц: проверки каждые 30 минут, скрипт наблюдения на странице оплаты и два магазина в тарифе.',
        ],
        'trial_days' => 14,
        'grace_days' => 14,
        'features' => [...$core, FeatureCatalog::PAYMENT_ATTEMPTS_SCRIPT],
        'limits' => [
            FeatureCatalog::MAX_ACTIVE_STORES => 2,
            FeatureCatalog::MAX_EXTRA_STORES => 3,
            FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 30,
            FeatureCatalog::MANUAL_RUNS_PER_DAY => 10,
            FeatureCatalog::MONTHLY_EVENT_BUDGET => 25000,
            FeatureCatalog::RETENTION_DAYS => 180,
            FeatureCatalog::MAX_ORDERS_PER_MONTH => 5000,
        ],
        'prices' => ['base' => '3900', 'extra_store' => '800'],
    ],
    'growth' => [
        'name' => ['en' => 'Growth', 'de' => 'Growth', 'ru' => 'Growth'],
        'description' => [
            'en' => 'For busy stores with up to 20,000 orders a month: checks every 15 minutes, three stores included and a year of history.',
            'de' => 'Für stark frequentierte Shops mit bis zu 20.000 Bestellungen im Monat: Prüfungen alle 15 Minuten, drei Shops inklusive und ein Jahr Verlauf.',
            'ru' => 'Для загруженных магазинов до 20 000 заказов в месяц: проверки каждые 15 минут, три магазина в тарифе и история за год.',
        ],
        'trial_days' => 14,
        'grace_days' => 14,
        'features' => [...$core, FeatureCatalog::PAYMENT_ATTEMPTS_SCRIPT],
        'limits' => [
            FeatureCatalog::MAX_ACTIVE_STORES => 3,
            FeatureCatalog::MAX_EXTRA_STORES => 5,
            FeatureCatalog::MIN_CHECK_INTERVAL_MINUTES => 15,
            FeatureCatalog::MANUAL_RUNS_PER_DAY => 20,
            FeatureCatalog::MONTHLY_EVENT_BUDGET => 100000,
            FeatureCatalog::RETENTION_DAYS => 365,
            FeatureCatalog::MAX_ORDERS_PER_MONTH => 20000,
        ],
        'prices' => ['base' => '7900', 'extra_store' => '700'],
    ],
    'enterprise' => [
        'name' => ['en' => 'Enterprise', 'de' => 'Enterprise', 'ru' => 'Enterprise'],
        'description' => [
            'en' => 'For stores with more than 20,000 orders a month or many stores: limits and price by agreement.',
            'de' => 'Für Shops mit mehr als 20.000 Bestellungen im Monat oder viele Shops: Limits und Preis nach Vereinbarung.',
            'ru' => 'Для магазинов больше 20 000 заказов в месяц или с большим числом магазинов: лимиты и цена по договорённости.',
        ],
        'trial_days' => 0,
        'grace_days' => 14,
        'contact_sales' => true,
        'features' => [...$core, FeatureCatalog::PAYMENT_ATTEMPTS_SCRIPT],
        'limits' => [],
        'prices' => [],
    ],
];
