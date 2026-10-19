<?php

return [
    'slug' => 'refund-policy',
    'policy_type' => 'recurring_payment',
    'sort' => 30,
    'locales' => [
        'en' => [
            'title' => 'Subscription and Refund Policy',
            'description' => 'Billing, automatic renewal, cancellation and refunds of Business Watchdog subscriptions.',
            'body' => <<<'HTML'
<p>Effective date: :effective_date</p>

<h2>1. Who sells the subscription</h2>
<p>Subscriptions to Business Watchdog are sold by our online reseller Paddle.com, the Merchant of Record for all our orders. Paddle charges the payments, issues the invoices, calculates taxes and processes refunds. This policy applies in addition to Paddle’s buyer terms.</p>

<h2>2. Free trial</h2>
<p>A plan may include a free trial of the number of days shown on the pricing page. The trial requires no payment details and ends on its own; the subscription starts only when you buy a plan. A trial is available once per store.</p>

<h2>3. Billing and automatic renewal</h2>
<p>Subscriptions are paid in advance for the billing period you choose (for example monthly or yearly) and renew automatically at the end of each period for the same period and price until you cancel. The price, the period and the date of the next payment are shown in your account under Settings → Subscription. Additional stores, if your plan allows them, are billed with the subscription.</p>

<h2>4. Cancellation</h2>
<p>You can cancel at any time in your account under Settings → Subscription. Cancellation stops the next renewal; you keep access to the paid plan until the end of the current billing period. No further payments are taken after that.</p>

<h2>5. Plan changes</h2>
<p>Moving to a higher plan takes effect immediately; Paddle charges the difference for the rest of the current period. Moving to a lower plan takes effect at the start of the next period. If the lower plan allows fewer stores, you choose which stores stay active; stores beyond the limit are paused, and while a store is paused its results may deviate.</p>

<h2>6. Price changes</h2>
<p>A running subscription keeps its price. New prices apply to new purchases only. If we ever have to change the price of a running subscription, we inform you by email at least 30 days before the next renewal at the new price, and you can cancel before then.</p>

<h2>7. Failed payments</h2>
<p>If a renewal payment fails, Paddle retries it and asks you to update your payment method. If the payment is not made within 7 days, the paid features are restricted until the payment succeeds.</p>

<h2>8. Refunds</h2>
<ul>
<li><strong>14-day money-back guarantee:</strong> you can request a full refund of the first payment of a subscription within 14 days of that payment, without giving a reason.</li>
<li><strong>Renewals and later payments</strong> are not refunded, except where the law requires a refund or where the Service was unavailable for a substantial part of the period for reasons on our side.</li>
<li><strong>How to request:</strong> write to <a href="mailto::contact_email">:contact_email</a> with the email address of your account or the invoice number, or contact Paddle through the link in your receipt or at paddle.net.</li>
<li>Paddle refunds the amount, including taxes, to the original payment method; it usually arrives within 5–10 business days, depending on your bank.</li>
<li>Together with a refund under the money-back guarantee we cancel the subscription so that it does not renew.</li>
</ul>

<h2>9. Business customers</h2>
<p>The Service is offered to businesses only (see the <a href="/:locale/terms">Terms of Service</a>). If you nevertheless buy as a consumer, the statutory rights of withdrawal described in Paddle’s buyer terms apply in addition to this policy.</p>
HTML,
        ],
        'de' => [
            'title' => 'Abonnement- und Erstattungsbedingungen',
            'description' => 'Abrechnung, automatische Verlängerung, Kündigung und Erstattung von Business-Watchdog-Abonnements.',
            'body' => <<<'HTML'
<p>Stand: :effective_date</p>

<h2>1. Wer das Abonnement verkauft</h2>
<p>Abonnements für Business Watchdog verkauft unser Online-Wiederverkäufer Paddle.com, der Merchant of Record für alle unsere Bestellungen. Paddle zieht die Zahlungen ein, stellt die Rechnungen aus, berechnet die Steuern und wickelt Erstattungen ab. Diese Bedingungen gelten ergänzend zu den Käuferbedingungen von Paddle.</p>

<h2>2. Kostenloser Testzeitraum</h2>
<p>Ein Tarif kann einen kostenlosen Testzeitraum mit der auf der Preisseite genannten Anzahl von Tagen enthalten. Der Test erfordert keine Zahlungsdaten und endet von selbst; das Abonnement beginnt erst, wenn Sie einen Tarif kaufen. Ein Test ist je Shop einmal möglich.</p>

<h2>3. Abrechnung und automatische Verlängerung</h2>
<p>Abonnements werden im Voraus für den gewählten Abrechnungszeitraum (zum Beispiel monatlich oder jährlich) bezahlt und verlängern sich am Ende jedes Zeitraums automatisch um denselben Zeitraum zum selben Preis, bis Sie kündigen. Preis, Zeitraum und Datum der nächsten Zahlung sehen Sie in Ihrem Konto unter Einstellungen → Abonnement. Zusätzliche Shops werden, soweit Ihr Tarif sie erlaubt, mit dem Abonnement abgerechnet.</p>

<h2>4. Kündigung</h2>
<p>Sie können jederzeit in Ihrem Konto unter Einstellungen → Abonnement kündigen. Die Kündigung beendet die nächste Verlängerung; der bezahlte Tarif bleibt bis zum Ende des laufenden Abrechnungszeitraums nutzbar. Danach erfolgen keine weiteren Zahlungen.</p>

<h2>5. Tarifwechsel</h2>
<p>Der Wechsel in einen höheren Tarif wirkt sofort; Paddle berechnet die Differenz für den Rest des laufenden Zeitraums. Der Wechsel in einen niedrigeren Tarif wirkt zum Beginn des nächsten Zeitraums. Erlaubt der niedrigere Tarif weniger Shops, wählen Sie, welche Shops aktiv bleiben; Shops über dem Limit werden pausiert, und während der Pause können die Ergebnisse eines Shops abweichen.</p>

<h2>6. Preisänderungen</h2>
<p>Ein laufendes Abonnement behält seinen Preis. Neue Preise gelten nur für neue Käufe. Sollten wir den Preis eines laufenden Abonnements einmal ändern müssen, informieren wir Sie mindestens 30 Tage vor der nächsten Verlängerung zum neuen Preis per E-Mail, und Sie können vorher kündigen.</p>

<h2>7. Fehlgeschlagene Zahlungen</h2>
<p>Schlägt eine Verlängerungszahlung fehl, versucht Paddle sie erneut und bittet Sie, Ihre Zahlungsart zu aktualisieren. Erfolgt die Zahlung nicht innerhalb von 7 Tagen, werden die kostenpflichtigen Funktionen bis zur erfolgreichen Zahlung eingeschränkt.</p>

<h2>8. Erstattungen</h2>
<ul>
<li><strong>14-Tage-Geld-zurück-Garantie:</strong> Die erste Zahlung eines Abonnements erstatten wir auf Anfrage innerhalb von 14 Tagen nach dieser Zahlung vollständig, ohne Angabe von Gründen.</li>
<li><strong>Verlängerungen und spätere Zahlungen</strong> werden nicht erstattet, außer wenn das Gesetz eine Erstattung verlangt oder der Dienst aus von uns zu vertretenden Gründen während eines erheblichen Teils des Zeitraums nicht verfügbar war.</li>
<li><strong>So beantragen Sie eine Erstattung:</strong> Schreiben Sie an <a href="mailto::contact_email">:contact_email</a> mit der E-Mail-Adresse Ihres Kontos oder der Rechnungsnummer, oder wenden Sie sich über den Link in Ihrer Quittung oder unter paddle.net an Paddle.</li>
<li>Paddle erstattet den Betrag einschließlich Steuern auf die ursprüngliche Zahlungsart; er geht je nach Bank in der Regel innerhalb von 5–10 Werktagen ein.</li>
<li>Mit einer Erstattung nach der Geld-zurück-Garantie kündigen wir das Abonnement, damit es sich nicht verlängert.</li>
</ul>

<h2>9. Unternehmer</h2>
<p>Der Dienst richtet sich ausschließlich an Unternehmer (siehe <a href="/:locale/terms">Nutzungsbedingungen</a>). Kaufen Sie dennoch als Verbraucher, gelten ergänzend die in den Käuferbedingungen von Paddle beschriebenen gesetzlichen Widerrufsrechte.</p>
HTML,
        ],
        'ru' => [
            'title' => 'Политика подписки и возвратов',
            'description' => 'Оплата, автоматическое продление, отмена и возврат денег за подписки Business Watchdog.',
            'body' => <<<'HTML'
<p>Дата вступления в силу: :effective_date</p>

<h2>1. Кто продаёт подписку</h2>
<p>Подписки на Business Watchdog продаёт наш онлайн-реселлер Paddle.com — продавец (Merchant of Record) по всем нашим заказам. Paddle списывает платежи, выставляет счета, рассчитывает налоги и проводит возвраты. Эта политика действует в дополнение к условиям Paddle для покупателей.</p>

<h2>2. Бесплатный пробный период</h2>
<p>Тариф может включать бесплатный пробный период на указанное на странице цен число дней. Для пробного периода не нужны платёжные данные, и он заканчивается сам; подписка начинается, только когда вы покупаете тариф. Пробный период доступен один раз для каждого магазина.</p>

<h2>3. Оплата и автоматическое продление</h2>
<p>Подписка оплачивается вперёд за выбранный период (например, месяц или год) и автоматически продлевается в конце каждого периода на тот же период и по той же цене, пока вы её не отмените. Цена, период и дата следующего платежа видны в учётной записи: Настройки → Подписка. Дополнительные магазины, если тариф их допускает, оплачиваются вместе с подпиской.</p>

<h2>4. Отмена</h2>
<p>Отменить подписку можно в любой момент в учётной записи: Настройки → Подписка. Отмена останавливает следующее продление; доступ к оплаченному тарифу сохраняется до конца текущего периода. После этого платежи больше не списываются.</p>

<h2>5. Смена тарифа</h2>
<p>Переход на более дорогой тариф действует сразу; Paddle списывает разницу за остаток текущего периода. Переход на более дешёвый тариф действует с начала следующего периода. Если в новом тарифе меньше магазинов, вы выбираете, какие магазины останутся активными; магазины сверх лимита ставятся на паузу, и пока магазин на паузе, возможны отклонения в его результатах.</p>

<h2>6. Изменение цен</h2>
<p>Действующая подписка сохраняет свою цену. Новые цены применяются только к новым покупкам. Если нам всё же придётся изменить цену действующей подписки, мы сообщим об этом по электронной почте не менее чем за 30 дней до продления по новой цене, и вы сможете отменить подписку заранее.</p>

<h2>7. Неудачные платежи</h2>
<p>Если платёж за продление не прошёл, Paddle повторяет попытку и просит обновить способ оплаты. Если оплата не поступила в течение 7 дней, платные функции ограничиваются до успешной оплаты.</p>

<h2>8. Возвраты</h2>
<ul>
<li><strong>Гарантия возврата 14 дней:</strong> первый платёж по подписке мы полностью возвращаем по запросу в течение 14 дней после этого платежа, без объяснения причин.</li>
<li><strong>Продления и последующие платежи</strong> не возвращаются, кроме случаев, когда возврата требует закон или когда Сервис по нашей вине был недоступен значительную часть периода.</li>
<li><strong>Как запросить возврат:</strong> напишите на <a href="mailto::contact_email">:contact_email</a>, указав адрес электронной почты учётной записи или номер счёта, или обратитесь в Paddle по ссылке в чеке или на paddle.net.</li>
<li>Paddle возвращает сумму вместе с налогами на исходный способ оплаты; обычно деньги приходят в течение 5–10 рабочих дней в зависимости от банка.</li>
<li>Вместе с возвратом по гарантии мы отменяем подписку, чтобы она не продлилась.</li>
</ul>

<h2>9. Клиенты-предприниматели</h2>
<p>Сервис предназначен только для бизнеса (см. <a href="/:locale/terms">Условия использования</a>). Если вы всё же покупаете как потребитель, в дополнение к этой политике действуют предусмотренные законом права на отказ от договора, описанные в условиях Paddle для покупателей.</p>
HTML,
        ],
    ],
];
