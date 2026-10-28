<?php

return [
    'menu' => 'footer',
    'sort' => 10,
    'menu_labels' => ['ru' => 'Безопасность', 'en' => 'Security', 'de' => 'Sicherheit'],
    'locales' => [
        'en' => [
            'title' => 'Why Business Watchdog is safe for your store',
            'description' => 'Read-only access, no customer data, keys instead of passwords, servers in Germany and GDPR processing under Art. 28: what Business Watchdog reads and never does.',
            'body' => file_get_contents(__DIR__.'/security/en.html'),
        ],
        'de' => [
            'title' => 'Warum Business Watchdog für Ihren Shop sicher ist',
            'description' => 'Nur Lesezugriff, keine Kundendaten, Schlüssel statt Passwörter, Server in Deutschland und Auftragsverarbeitung nach Art. 28 DSGVO: was Business Watchdog liest und nie tut.',
            'body' => file_get_contents(__DIR__.'/security/de.html'),
        ],
        'ru' => [
            'title' => 'Почему Business Watchdog безопасен для вашего магазина',
            'description' => 'Только чтение, без данных покупателей, ключи вместо паролей, серверы в Германии и обработка по ст. 28 GDPR: что Business Watchdog читает и чего не делает никогда.',
            'body' => file_get_contents(__DIR__.'/security/ru.html'),
        ],
    ],
];
