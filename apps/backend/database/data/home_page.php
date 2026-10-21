<?php

return [
    'ru' => [
        'title' => 'Мониторинг WooCommerce и Shopware: оплата и заказы | Business Watchdog',
        'description' => 'Проверяйте путь до оплаты в WooCommerce и Shopware, отслеживайте ошибки и сверяйте заказы со Stripe и PayPal. Начните с проверки по адресу магазина.',
        'body' => file_get_contents(__DIR__.'/home_page/ru.html'),
    ],
    'en' => [
        'title' => 'WooCommerce & Shopware Payment Monitoring | Business Watchdog',
        'description' => 'Check the path to payment in WooCommerce and Shopware, spot failures and reconcile orders with Stripe and PayPal. Start with a public store URL check.',
        'body' => file_get_contents(__DIR__.'/home_page/en.html'),
    ],
    'de' => [
        'title' => 'WooCommerce & Shopware Monitoring | Business Watchdog',
        'description' => 'Prüfen Sie den Weg zur Zahlung in WooCommerce und Shopware, erkennen Sie Fehler und gleichen Sie Bestellungen mit Stripe und PayPal ab. Jetzt Shop per URL prüfen.',
        'body' => file_get_contents(__DIR__.'/home_page/de.html'),
    ],
];
