<?php

$manifest = [
    'woocommerce-payment-pending' => ['woocommerce', 10],
    'woocommerce-missing-orders' => ['woocommerce', 20],
    'woocommerce-reconciliation' => ['woocommerce', 30],
    'woocommerce-order-processing' => ['woocommerce', 40],
    'stripe-woocommerce-mismatch' => ['payments', 10],
    'paypal-pending-orders' => ['payments', 20],
    'stripe-reconciliation' => ['payments', 30],
    'shopware-reconciliation' => ['shopware', 10],
    'shopware-order-monitoring' => ['shopware', 20],
];
$render = require __DIR__.'/render.php';
$common = json_decode(file_get_contents(__DIR__.'/common.json'), true, flags: JSON_THROW_ON_ERROR);
$copy = [];
foreach (['en', 'de', 'ru'] as $locale) {
    $copy[$locale] = json_decode(file_get_contents(__DIR__."/{$locale}.json"), true, flags: JSON_THROW_ON_ERROR);
}
$pages = [];
foreach ($manifest as $slug => [$section, $sort]) {
    $pages[$slug] = ['menu' => 'header', 'section' => $section, 'sort' => $sort, 'menu_labels' => [], 'locales' => []];
    foreach (['en', 'de', 'ru'] as $locale) {
        $page = $copy[$locale][$slug];
        $pages[$slug]['menu_labels'][$locale] = $page['label'];
        $pages[$slug]['locales'][$locale] = [
            'title' => $page['title'],
            'description' => $page['description'],
            'body' => $render($page, $common[$locale], $locale, $copy[$locale]),
        ];
    }
}

return ['sections' => [
    'woocommerce' => ['labels' => ['ru' => 'WooCommerce', 'en' => 'WooCommerce', 'de' => 'WooCommerce'], 'sort' => 20],
    'payments' => ['labels' => ['ru' => 'Stripe и PayPal', 'en' => 'Stripe & PayPal', 'de' => 'Stripe & PayPal'], 'sort' => 30],
    'shopware' => ['labels' => ['ru' => 'Shopware', 'en' => 'Shopware', 'de' => 'Shopware'], 'sort' => 40],
], 'pages' => $pages];
