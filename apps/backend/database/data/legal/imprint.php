<?php

return [
    'slug' => 'imprint',
    'policy_type' => null,
    'sort' => 40,
    'locales' => [
        'en' => [
            'title' => 'Legal Notice',
            'description' => 'Provider of Business Watchdog and contact details.',
            'body' => <<<'HTML'
<h2>Provider</h2>
<p><strong>:seller_name</strong><br>:seller_address_lines</p>

<h2>Contact</h2>
<p>Email: <a href="mailto::contact_email">:contact_email</a><br>Contact form: <a href="/:locale/contact">business-watchdog.com/:locale/contact</a></p>

<h2>Responsible for the content</h2>
<p>:seller_name, address as above.</p>

<h2>Payments</h2>
<p>Subscriptions are sold by Paddle.com Market Limited, Judd House, 18–29 Mora Street, London EC1V 8BT, United Kingdom, as Merchant of Record.</p>

<h2>Dispute resolution</h2>
<p>The Service is offered to businesses only. We are neither obliged nor willing to take part in dispute resolution proceedings before a consumer arbitration board.</p>
HTML,
        ],
        'de' => [
            'title' => 'Impressum',
            'description' => 'Anbieterkennzeichnung von Business Watchdog nach § 5 DDG.',
            'body' => <<<'HTML'
<h2>Angaben gemäß § 5 DDG</h2>
<p><strong>:seller_name</strong><br>:seller_address_lines</p>

<h2>Kontakt</h2>
<p>E-Mail: <a href="mailto::contact_email">:contact_email</a><br>Kontaktformular: <a href="/:locale/contact">business-watchdog.com/:locale/contact</a></p>

<h2>Verantwortlich für den Inhalt nach § 18 Abs. 2 MStV</h2>
<p>:seller_name, Anschrift wie oben.</p>

<h2>Zahlungsabwicklung</h2>
<p>Abonnements verkauft die Paddle.com Market Limited, Judd House, 18–29 Mora Street, London EC1V 8BT, Vereinigtes Königreich, als Merchant of Record.</p>

<h2>Streitbeilegung</h2>
<p>Der Dienst richtet sich ausschließlich an Unternehmer. Wir sind nicht bereit und nicht verpflichtet, an Streitbeilegungsverfahren vor einer Verbraucherschlichtungsstelle teilzunehmen.</p>
HTML,
        ],
        'ru' => [
            'title' => 'Правовая информация',
            'description' => 'Кто предоставляет Business Watchdog и как с нами связаться.',
            'body' => <<<'HTML'
<h2>Поставщик услуги</h2>
<p><strong>:seller_name</strong><br>:seller_address_lines</p>

<h2>Контакты</h2>
<p>Электронная почта: <a href="mailto::contact_email">:contact_email</a><br>Форма обратной связи: <a href="/:locale/contact">business-watchdog.com/:locale/contact</a></p>

<h2>Ответственный за содержание</h2>
<p>:seller_name, адрес указан выше.</p>

<h2>Платежи</h2>
<p>Подписки продаёт Paddle.com Market Limited, Judd House, 18–29 Mora Street, London EC1V 8BT, Великобритания, как продавец (Merchant of Record).</p>

<h2>Урегулирование споров</h2>
<p>Сервис предназначен только для бизнеса. Мы не обязаны и не готовы участвовать в процедурах урегулирования споров в арбитражных комиссиях по защите прав потребителей.</p>
HTML,
        ],
    ],
];
