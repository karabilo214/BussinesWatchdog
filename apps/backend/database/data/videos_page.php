<?php

return [
    'en' => [
        'title' => 'See Business Watchdog work in a minute',
        'description' => 'Three short videos recorded on a test shop: the checkout check, a payment provider failure customers would hit, and an order marked paid without money.',
        'body' => file_get_contents(__DIR__.'/videos_page/en.html'),
    ],
    'de' => [
        'title' => 'In einer Minute sehen, wie Business Watchdog arbeitet',
        'description' => 'Drei kurze Videos aus einem Testshop: die Checkout-Prüfung, ein Fehler beim Zahlungsanbieter, an dem Kunden scheitern, und eine als bezahlt markierte Bestellung ohne Geld.',
        'body' => file_get_contents(__DIR__.'/videos_page/de.html'),
    ],
    'ru' => [
        'title' => 'Посмотрите, как работает Business Watchdog, за минуту',
        'description' => 'Три коротких видео с тестового магазина: проверка оформления заказа, сбой платёжного провайдера, из-за которого покупатели не могут оплатить, и заказ, отмеченный оплаченным без денег.',
        'body' => file_get_contents(__DIR__.'/videos_page/ru.html'),
    ],
];
