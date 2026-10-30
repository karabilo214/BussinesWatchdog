<?php

return [
    'uploaded' => '2026-10-27',
    'videos' => [
        'checkout-check' => [
            'files' => ['de' => ['de-checkout-pruefung', 48], 'en' => ['en-checkout-check', 48], 'ru' => ['en-checkout-check', 48]],
            'de' => [
                'title' => 'Video: Die Checkout-Prüfung von Business Watchdog',
                'h1' => 'Die Checkout-Prüfung',
                'description' => 'Ein Browser geht Ihren Shop wie ein Kunde durch – Produkt, Warenkorb, Kasse – und prüft, ob das Zahlungsformular erscheint. Ohne Bestellung, ohne Kartendaten.',
                'steps' => ['Der Browser öffnet die Produktseite und legt den Artikel in den Warenkorb.', 'Er prüft den Warenkorb: das richtige Produkt, ein positiver Betrag.', 'Er geht zur Kasse und wählt den Versand.', 'Er prüft, dass die Zahlungsarten und das Zahlungsformular erscheinen – und hält vor „Zahlungspflichtig bestellen“ an.'],
            ],
            'en' => [
                'title' => 'Video: the Business Watchdog checkout check',
                'h1' => 'The checkout check',
                'description' => 'A browser walks your shop like a customer — product, cart, checkout — and checks that the payment form appears. No order is placed, no card data entered.',
                'steps' => ['The browser opens the product page and adds the item to the cart.', 'It checks the cart: the right product, a positive amount.', 'It goes to the checkout and chooses shipping.', 'It checks that payment methods and the payment form appear — and stops before the order button.'],
            ],
            'ru' => [
                'title' => 'Видео: проверка оформления заказа Business Watchdog',
                'h1' => 'Проверка оформления заказа',
                'description' => 'Браузер проходит магазин как покупатель — товар, корзина, оформление — и проверяет, что форма оплаты появилась. Заказ не оформляется, данные карты не вводятся.',
                'steps' => ['Браузер открывает страницу товара и кладёт товар в корзину.', 'Проверяет корзину: тот самый товар, положительная сумма.', 'Переходит к оформлению и выбирает доставку.', 'Проверяет, что появились способы оплаты и форма оплаты, — и останавливается перед кнопкой заказа.'],
            ],
        ],
        'payment-provider-failure' => [
            'files' => ['de' => ['de-zahlungsanbieter-fehler', 53], 'en' => ['en-payment-provider-failure', 51], 'ru' => ['en-payment-provider-failure', 51]],
            'de' => [
                'title' => 'Video: Wenn Kunden nicht zahlen können',
                'h1' => 'Wenn Kunden nicht zahlen können',
                'description' => 'Der Stripe-Schlüssel des Shops funktioniert nicht mehr: Kunden sehen einen Fehler statt des Kartenformulars. Die Prüfung bemerkt es, nennt die Ursache und warnt Sie.',
                'steps' => ['Im Shop ist ein ungültiger Stripe-Schlüssel hinterlegt.', 'Die Checkout-Prüfung erreicht die Kasse, aber das Kartenformular lädt nicht – Stripe lehnt den Schlüssel ab.', 'Business Watchdog meldet „Kunden können nicht bezahlen“ mit der Ursache und dem Schritt.', 'Sie erhalten die Warnung per E-Mail oder Telegram.'],
            ],
            'en' => [
                'title' => 'Video: when customers cannot pay',
                'h1' => 'When customers cannot pay',
                'description' => 'The Stripe key of the shop stopped working: customers see an error instead of the card form. The check notices it, names the cause and alerts you.',
                'steps' => ['The shop has an invalid Stripe key.', 'The checkout check reaches the checkout, but the card form does not load — Stripe rejects the key.', 'Business Watchdog reports «customers cannot pay» with the cause and the step.', 'You get the alert by e-mail or Telegram.'],
            ],
            'ru' => [
                'title' => 'Видео: когда покупатели не могут оплатить',
                'h1' => 'Когда покупатели не могут оплатить',
                'description' => 'Ключ Stripe магазина перестал работать: покупатели видят ошибку вместо формы карты. Проверка это замечает, называет причину и предупреждает вас.',
                'steps' => ['В магазине указан недействительный ключ Stripe.', 'Проверка доходит до оформления, но форма карты не загружается — Stripe отклоняет ключ.', 'Business Watchdog сообщает «покупатели не могут оплатить» с причиной и шагом.', 'Предупреждение приходит на e-mail или в Telegram.'],
            ],
        ],
        'paid-without-money' => [
            'files' => ['de' => ['de-bezahlt-ohne-geld', 84], 'en' => ['en-paid-without-money', 81], 'ru' => ['en-paid-without-money', 81]],
            'de' => [
                'title' => 'Video: Als bezahlt markiert, aber kein Geld',
                'h1' => 'Als bezahlt markiert, aber kein Geld',
                'description' => 'Eine Karte wurde abgelehnt, die Bestellung steht trotzdem auf bezahlt. Business Watchdog vergleicht die Bestellung mit Stripe und meldet die Lücke von 18,40 €.',
                'steps' => ['Ein Kunde zahlt mit einer Karte, die abgelehnt wird.', 'Im Shop steht die Bestellung trotzdem auf „bezahlt“.', 'Business Watchdog gleicht die Bestellung mit Stripe ab: Es gibt keine Zahlung.', 'Sie erhalten einen Vorfall mit Betrag, Bestellnummer und Beleg – bevor Sie die Ware versenden.'],
            ],
            'en' => [
                'title' => 'Video: marked as paid, but no money',
                'h1' => 'Marked as paid, but no money',
                'description' => 'A card was declined, yet the order ended up as paid. Business Watchdog compares the order with Stripe and reports the €18.40 gap.',
                'steps' => ['A customer pays with a card that is declined.', 'In the shop the order is still marked «paid».', 'Business Watchdog compares the order with Stripe: there is no payment.', 'You get an incident with the amount, order number and evidence — before you ship.'],
            ],
            'ru' => [
                'title' => 'Видео: отмечен оплаченным, а денег нет',
                'h1' => 'Отмечен оплаченным, а денег нет',
                'description' => 'Карта отклонена, а заказ всё равно стал оплаченным. Business Watchdog сверяет заказ со Stripe и сообщает о расхождении 18,40 €.',
                'steps' => ['Покупатель платит картой, которую банк отклоняет.', 'В магазине заказ всё равно отмечен «оплачен».', 'Business Watchdog сверяет заказ со Stripe: оплаты нет.', 'Вы получаете инцидент с суммой, номером заказа и доказательством — до отправки товара.'],
            ],
        ],
    ],
    'copy' => [
        'de' => ['eyebrow' => 'Video', 'what' => 'Was Sie im Video sehen', 'more' => 'Weitere Videos', 'all' => 'Alle Videos', 'note' => 'Aufgenommen in einem Testshop mit Stripe im Testmodus; Shopname und Kunde sind erfunden.', 'download' => 'Video herunterladen', 'start' => '14 Tage kostenlos starten', 'check' => 'Shop kostenlos prüfen', 'lang_note' => null],
        'en' => ['eyebrow' => 'Video', 'what' => 'What you see in the video', 'more' => 'More videos', 'all' => 'All videos', 'note' => 'Recorded on a test shop with Stripe in test mode; the shop name and customer are made up.', 'download' => 'Download the video', 'start' => 'Start 14 days free', 'check' => 'Check your store for free', 'lang_note' => null],
        'ru' => ['eyebrow' => 'Видео', 'what' => 'Что вы увидите в видео', 'more' => 'Другие видео', 'all' => 'Все видео', 'note' => 'Записано на тестовом магазине со Stripe в тестовом режиме; название магазина и покупатель вымышлены.', 'download' => 'Скачать видео', 'start' => '14 дней бесплатно', 'check' => 'Проверить магазин бесплатно', 'lang_note' => 'Видео на английском языке.'],
    ],
];
