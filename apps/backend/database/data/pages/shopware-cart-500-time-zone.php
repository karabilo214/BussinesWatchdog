<?php

$render = require __DIR__.'/cases/render.php';
$content = require __DIR__.'/cases/shopware-cart-500-time-zone.php';

return [
    'menu' => 'none',
    'sort' => 0,
    'locales' => array_map(static fn (array $locale): array => [
        'title' => $locale['title'],
        'description' => $locale['description'],
        'body' => $locale['render'],
    ], array_combine(array_keys($content), array_map(
        static fn (string $code, array $locale): array => $locale + ['render' => $render($locale['c'], $code)],
        array_keys($content),
        $content,
    ))),
];
