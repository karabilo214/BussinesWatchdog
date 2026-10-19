<?php

return [
    'slug' => 'privacy',
    'policy_type' => 'privacy_notice',
    'sort' => 20,
    'locales' => [
        'en' => [
            'title' => 'Privacy Policy',
            'description' => 'How Business Watchdog processes personal data of website visitors and account users.',
            'body' => <<<'HTML'
<p>Effective date: :effective_date</p>

<h2>1. Controller</h2>
<p>The controller for the processing described here is <strong>:seller_name</strong>, :seller_address. Contact for all privacy matters: <a href="mailto::contact_email">:contact_email</a>. We operate the Service from Ukraine; its servers and backups are located in Germany (EU).</p>

<h2>2. Visiting the website</h2>
<p>The pages of the public website set no cookies, except the pages with a form (Section 10), and use no analytics, tracking, advertising or social media tools, and loads no fonts or scripts from third parties. Our web server keeps no access logs. To deliver the pages, your browser sends technical data (IP address, time, requested address, browser type); it is used only to answer the request. Error logs of the application may briefly contain such data; they are rotated and overwritten automatically. Legal basis: Art. 6(1)(f) GDPR (secure and reliable operation of the website).</p>

<h2>3. Contact form and email</h2>
<p>When you write to us via the contact form or by email, we process your name, email address, your message and our correspondence in order to answer you. Legal basis: Art. 6(1)(b) GDPR where the request concerns a contract, otherwise Art. 6(1)(f) GDPR. Messages from the contact form are deleted one year after the request is closed; emails are kept as long as needed for the matter and statutory retention.</p>

<h2>4. Free store check on the website</h2>
<p>When you check a store by its address on the website, we process the address entered and a one-way hash of your IP address to prevent abuse (a repeated check of the same store or from the same address is blocked for a while). Screenshots of the check are deleted five minutes after it ends, the check record after 90 days. The check never places an order and never enters personal data into the store. Legal basis: Art. 6(1)(f) GDPR (providing the free check and protecting it from abuse).</p>

<h2>5. Account and use of the Service</h2>
<ul>
<li><strong>Account data:</strong> name, email address, password (stored only as a hash), language, time zone, the organisation name, the secret of the authenticator app (encrypted), invitations of team members (their email address and role).</li>
<li><strong>Sign-in and security:</strong> a session record with IP address and browser information while you are signed in; sessions expire after two hours of inactivity. Actions in the account are recorded in an audit log (who, what, when); records of the operator’s administration are kept for two years.</li>
<li><strong>Consent to documents:</strong> which version of these documents you accepted and when (without the IP address).</li>
<li><strong>Notifications:</strong> the email addresses and, if you connect Telegram, the Telegram chat to which alerts are sent.</li>
</ul>
<p>Legal basis: Art. 6(1)(b) GDPR (providing the Service under the contract) and Art. 6(1)(f) GDPR (security of the Service, proof of consent). We keep account data for the lifetime of the account and delete it within 30 days after the account is closed or on your request, unless we must keep it by law.</p>

<h2>6. Data of your stores</h2>
<p>Store addresses, connection keys (encrypted), order and payment records (status, amounts, currencies, payment method, transaction references, timestamps), incidents and screenshots of storefront pages taken during checks (kept 30 days) are processed on behalf of your organisation as described in Section 15 of the <a href="/:locale/terms">Terms of Service</a>. The Service is built not to collect the names, addresses or email addresses of your customers. Browser checks use test data only.</p>

<h2>7. Payments</h2>
<p>Purchases of subscriptions are handled by Paddle.com Market Limited, London, United Kingdom, as Merchant of Record. Paddle collects your billing and payment data as its own controller under its privacy notice. We receive from Paddle the data needed to link the subscription to your account: subscription status, plan, invoices and amounts, Paddle’s customer identifier and the billing email address. We never see your card details. Legal basis: Art. 6(1)(b) and (c) GDPR; billing records are kept as long as tax and commercial law require.</p>

<h2>8. Recipients and processors</h2>
<ul>
<li>Contabo GmbH, Munich, Germany — servers and encrypted backup storage (processor).</li>
<li>Paddle.com Market Limited, United Kingdom — sale and payment of subscriptions (own controller; the United Kingdom has an EU adequacy decision).</li>
<li>Telegram — only if you connect a Telegram chat for alerts; the alert text is sent to Telegram’s servers.</li>
<li>Stores and payment providers you connect (for example WooCommerce, Shopware, Stripe, PayPal) — we read data there on your instruction; we do not send them your personal data.</li>
</ul>
<p>We send email from our own mail server; we do not use newsletter or email tracking tools. We do not sell personal data.</p>

<h2>9. Backups</h2>
<p>The database and mailboxes are backed up daily, encrypted and stored in Germany. Backups are kept on a schedule: all backups of the current week, the last one of each earlier week of the current month and the last one of each month for twelve months. Deleted data therefore disappears from backups at the latest after this period.</p>

<h2>10. Cookies</h2>
<p>The customer account and the pages of the website with a form (contact, store check, sign-up) use only technically necessary cookies: a session cookie and a protection cookie against cross-site request forgery. They are required to provide the service you request (§ 25(2) TDDDG, Art. 6(1)(b) GDPR) and expire with the session. When you open the payment step, the checkout of Paddle is loaded from Paddle’s servers and may set the cookies Paddle needs for the payment, under Paddle’s privacy notice. No consent banner is needed because we use no other cookies.</p>

<h2>11. Your rights</h2>
<p>You have the right of access (Art. 15 GDPR), rectification (Art. 16), erasure (Art. 17), restriction of processing (Art. 18), data portability (Art. 20) and to object to processing based on legitimate interests (Art. 21). You can withdraw a consent at any time with effect for the future. Write to <a href="mailto::contact_email">:contact_email</a>. You also have the right to lodge a complaint with a data protection supervisory authority, in particular in the EU member state of your residence or workplace (Art. 77 GDPR).</p>

<h2>12. Security</h2>
<p>We use encrypted connections (TLS), store keys and secrets encrypted, protect administrative access with two-factor authentication, separate the data of each organisation and encrypt backups.</p>

<h2>13. Automated decisions</h2>
<p>We do not make decisions based solely on automated processing that have legal or similarly significant effects on you, and we do not use artificial intelligence to process your data.</p>

<h2>14. Changes</h2>
<p>We update this policy when the Service or the law changes. The current version is always available on this page; we inform account holders of material changes by email.</p>
HTML,
        ],
        'de' => [
            'title' => 'Datenschutzerklärung',
            'description' => 'Wie Business Watchdog personenbezogene Daten von Website-Besuchern und Kontonutzern verarbeitet.',
            'body' => <<<'HTML'
<p>Stand: :effective_date</p>

<h2>1. Verantwortlicher</h2>
<p>Verantwortlicher für die hier beschriebene Verarbeitung ist <strong>:seller_name</strong>, :seller_address. Kontakt in allen Datenschutzfragen: <a href="mailto::contact_email">:contact_email</a>. Wir betreiben den Dienst aus der Ukraine; Server und Sicherungen befinden sich in Deutschland (EU).</p>

<h2>2. Besuch der Website</h2>
<p>Die Seiten der öffentlichen Website setzen keine Cookies, außer den Seiten mit einem Formular (Ziffer 10), und nutzen keine Analyse-, Tracking-, Werbe- oder Social-Media-Werkzeuge und laden keine Schriften oder Skripte von Dritten. Unser Webserver führt keine Zugriffsprotokolle. Zur Auslieferung der Seiten übermittelt Ihr Browser technische Daten (IP-Adresse, Zeitpunkt, aufgerufene Adresse, Browsertyp); sie werden nur zur Beantwortung der Anfrage verwendet. Fehlerprotokolle der Anwendung können solche Daten kurzzeitig enthalten; sie werden automatisch rotiert und überschrieben. Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO (sicherer und zuverlässiger Betrieb der Website).</p>

<h2>3. Kontaktformular und E-Mail</h2>
<p>Wenn Sie uns über das Kontaktformular oder per E-Mail schreiben, verarbeiten wir Ihren Namen, Ihre E-Mail-Adresse, Ihre Nachricht und unsere Korrespondenz, um Ihnen zu antworten. Rechtsgrundlage: Art. 6 Abs. 1 lit. b DSGVO, soweit die Anfrage einen Vertrag betrifft, sonst Art. 6 Abs. 1 lit. f DSGVO. Nachrichten aus dem Kontaktformular löschen wir ein Jahr nach Abschluss der Anfrage; E-Mails bewahren wir so lange auf, wie es für das Anliegen und gesetzliche Aufbewahrungspflichten erforderlich ist.</p>

<h2>4. Kostenlose Shop-Prüfung auf der Website</h2>
<p>Wenn Sie auf der Website einen Shop anhand seiner Adresse prüfen, verarbeiten wir die eingegebene Adresse und einen Einweg-Hash Ihrer IP-Adresse zum Schutz vor Missbrauch (eine erneute Prüfung desselben Shops oder von derselben Adresse ist eine Zeit lang gesperrt). Screenshots der Prüfung werden fünf Minuten nach deren Ende gelöscht, der Prüfdatensatz nach 90 Tagen. Die Prüfung gibt nie eine Bestellung auf und gibt keine personenbezogenen Daten in den Shop ein. Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO (Bereitstellung der kostenlosen Prüfung und Schutz vor Missbrauch).</p>

<h2>5. Konto und Nutzung des Dienstes</h2>
<ul>
<li><strong>Kontodaten:</strong> Name, E-Mail-Adresse, Passwort (nur als Hash gespeichert), Sprache, Zeitzone, Name der Organisation, Geheimnis der Authenticator-App (verschlüsselt), Einladungen von Teammitgliedern (E-Mail-Adresse und Rolle).</li>
<li><strong>Anmeldung und Sicherheit:</strong> ein Sitzungsdatensatz mit IP-Adresse und Browserinformationen, solange Sie angemeldet sind; Sitzungen enden nach zwei Stunden Inaktivität. Handlungen im Konto werden in einem Prüfprotokoll erfasst (wer, was, wann); Protokolle der Verwaltung durch den Betreiber bewahren wir zwei Jahre auf.</li>
<li><strong>Zustimmung zu Dokumenten:</strong> welche Fassung dieser Dokumente Sie wann akzeptiert haben (ohne IP-Adresse).</li>
<li><strong>Benachrichtigungen:</strong> die E-Mail-Adressen und, wenn Sie Telegram verbinden, der Telegram-Chat, an den Warnungen gesendet werden.</li>
</ul>
<p>Rechtsgrundlage: Art. 6 Abs. 1 lit. b DSGVO (Erbringung des Dienstes nach dem Vertrag) und Art. 6 Abs. 1 lit. f DSGVO (Sicherheit des Dienstes, Nachweis der Zustimmung). Kontodaten speichern wir für die Dauer des Kontos und löschen sie innerhalb von 30 Tagen nach Schließung des Kontos oder auf Ihren Wunsch, soweit keine gesetzliche Aufbewahrungspflicht besteht.</p>

<h2>6. Daten Ihrer Shops</h2>
<p>Shop-Adressen, Verbindungsschlüssel (verschlüsselt), Bestell- und Zahlungsdatensätze (Status, Beträge, Währungen, Zahlungsart, Transaktionsreferenzen, Zeitpunkte), Vorfälle und bei Prüfungen erstellte Screenshots von Shopseiten (30 Tage gespeichert) verarbeiten wir im Auftrag Ihrer Organisation gemäß Ziffer 15 der <a href="/:locale/terms">Nutzungsbedingungen</a>. Der Dienst ist so gebaut, dass er Namen, Anschriften und E-Mail-Adressen Ihrer Kunden nicht erhebt. Browserprüfungen verwenden ausschließlich Testdaten.</p>

<h2>7. Zahlungen</h2>
<p>Den Kauf von Abonnements wickelt Paddle.com Market Limited, London, Vereinigtes Königreich, als Merchant of Record ab. Paddle erhebt Ihre Rechnungs- und Zahlungsdaten als eigener Verantwortlicher nach seiner Datenschutzerklärung. Wir erhalten von Paddle die Daten, die nötig sind, um das Abonnement Ihrem Konto zuzuordnen: Status des Abonnements, Tarif, Rechnungen und Beträge, die Kundenkennung von Paddle und die Rechnungs-E-Mail-Adresse. Ihre Kartendaten sehen wir nie. Rechtsgrundlage: Art. 6 Abs. 1 lit. b und c DSGVO; Abrechnungsunterlagen bewahren wir so lange auf, wie Steuer- und Handelsrecht es verlangen.</p>

<h2>8. Empfänger und Auftragsverarbeiter</h2>
<ul>
<li>Contabo GmbH, München, Deutschland — Server und verschlüsselter Sicherungsspeicher (Auftragsverarbeiter).</li>
<li>Paddle.com Market Limited, Vereinigtes Königreich — Verkauf und Bezahlung der Abonnements (eigener Verantwortlicher; für das Vereinigte Königreich besteht ein Angemessenheitsbeschluss der EU).</li>
<li>Telegram — nur, wenn Sie einen Telegram-Chat für Warnungen verbinden; der Text der Warnung wird an die Server von Telegram übermittelt.</li>
<li>Von Ihnen verbundene Shops und Zahlungsanbieter (zum Beispiel WooCommerce, Shopware, Stripe, PayPal) — wir lesen dort Daten auf Ihre Weisung; Ihre personenbezogenen Daten übermitteln wir ihnen nicht.</li>
</ul>
<p>E-Mails versenden wir über unseren eigenen Mailserver; Newsletter- oder E-Mail-Tracking-Werkzeuge nutzen wir nicht. Wir verkaufen keine personenbezogenen Daten.</p>

<h2>9. Datensicherungen</h2>
<p>Datenbank und Postfächer werden täglich gesichert, verschlüsselt und in Deutschland gespeichert. Sicherungen werden nach einem Plan aufbewahrt: alle Sicherungen der laufenden Woche, die letzte jeder früheren Woche des laufenden Monats und die letzte jedes Monats für zwölf Monate. Gelöschte Daten verschwinden daher spätestens nach diesem Zeitraum auch aus den Sicherungen.</p>

<h2>10. Cookies</h2>
<p>Das Kundenkonto und die Seiten der Website mit einem Formular (Kontakt, Shop-Prüfung, Registrierung) verwenden nur technisch notwendige Cookies: ein Sitzungs-Cookie und ein Schutz-Cookie gegen Cross-Site-Request-Forgery. Sie sind für die von Ihnen gewünschte Leistung erforderlich (§ 25 Abs. 2 TDDDG, Art. 6 Abs. 1 lit. b DSGVO) und enden mit der Sitzung. Wenn Sie den Zahlungsschritt öffnen, wird der Checkout von Paddle von den Servern von Paddle geladen und kann die Cookies setzen, die Paddle für die Zahlung benötigt, nach der Datenschutzerklärung von Paddle. Ein Einwilligungsbanner ist nicht erforderlich, da wir keine anderen Cookies verwenden.</p>

<h2>11. Ihre Rechte</h2>
<p>Sie haben das Recht auf Auskunft (Art. 15 DSGVO), Berichtigung (Art. 16), Löschung (Art. 17), Einschränkung der Verarbeitung (Art. 18), Datenübertragbarkeit (Art. 20) und auf Widerspruch gegen Verarbeitungen auf Grundlage berechtigter Interessen (Art. 21). Eine Einwilligung können Sie jederzeit mit Wirkung für die Zukunft widerrufen. Schreiben Sie an <a href="mailto::contact_email">:contact_email</a>. Außerdem haben Sie das Recht, sich bei einer Datenschutz-Aufsichtsbehörde zu beschweren, insbesondere in dem EU-Mitgliedstaat Ihres Aufenthaltsorts oder Arbeitsplatzes (Art. 77 DSGVO).</p>

<h2>12. Sicherheit</h2>
<p>Wir nutzen verschlüsselte Verbindungen (TLS), speichern Schlüssel und Geheimnisse verschlüsselt, schützen administrative Zugänge mit Zwei-Faktor-Authentifizierung, trennen die Daten jeder Organisation und verschlüsseln Sicherungen.</p>

<h2>13. Automatisierte Entscheidungen</h2>
<p>Wir treffen keine ausschließlich automatisierten Entscheidungen, die Ihnen gegenüber rechtliche Wirkung entfalten oder Sie in ähnlicher Weise erheblich beeinträchtigen, und setzen keine künstliche Intelligenz zur Verarbeitung Ihrer Daten ein.</p>

<h2>14. Änderungen</h2>
<p>Wir aktualisieren diese Erklärung, wenn sich der Dienst oder das Recht ändert. Die aktuelle Fassung steht immer auf dieser Seite; über wesentliche Änderungen informieren wir Kontoinhaber per E-Mail.</p>
HTML,
        ],
        'ru' => [
            'title' => 'Политика конфиденциальности',
            'description' => 'Как Business Watchdog обрабатывает персональные данные посетителей сайта и пользователей учётных записей.',
            'body' => <<<'HTML'
<p>Дата вступления в силу: :effective_date</p>

<h2>1. Ответственный за обработку</h2>
<p>За описанную здесь обработку отвечает <strong>:seller_name</strong>, :seller_address. Контакт по всем вопросам персональных данных: <a href="mailto::contact_email">:contact_email</a>. Мы управляем Сервисом из Украины; серверы и резервные копии находятся в Германии (ЕС).</p>

<h2>2. Посещение сайта</h2>
<p>Страницы публичного сайта не устанавливают cookie, кроме страниц с формой (раздел 10), и не используют инструменты аналитики, отслеживания, рекламы или социальных сетей и не загружают шрифты и скрипты сторонних сервисов. Наш веб-сервер не ведёт журналы обращений. Чтобы показать страницу, ваш браузер передаёт технические данные (IP-адрес, время, запрошенный адрес, тип браузера); они используются только для ответа на запрос. Журналы ошибок приложения могут недолго содержать такие данные; они автоматически ротируются и перезаписываются. Правовое основание: ст. 6(1)(f) GDPR (безопасная и надёжная работа сайта).</p>

<h2>3. Форма обратной связи и электронная почта</h2>
<p>Когда вы пишете нам через форму обратной связи или по электронной почте, мы обрабатываем ваше имя, адрес электронной почты, сообщение и нашу переписку, чтобы ответить вам. Правовое основание: ст. 6(1)(b) GDPR, если обращение касается договора, иначе ст. 6(1)(f) GDPR. Сообщения из формы мы удаляем через год после закрытия обращения; письма храним столько, сколько нужно для дела и по требованиям закона о хранении.</p>

<h2>4. Бесплатная проверка магазина на сайте</h2>
<p>Когда вы проверяете магазин по адресу на сайте, мы обрабатываем введённый адрес и необратимый хэш вашего IP-адреса для защиты от злоупотреблений (повторная проверка того же магазина или с того же адреса на время блокируется). Снимки экрана проверки удаляются через пять минут после её окончания, запись о проверке — через 90 дней. Проверка никогда не оформляет заказ и не вводит персональные данные в магазин. Правовое основание: ст. 6(1)(f) GDPR (бесплатная проверка и её защита от злоупотреблений).</p>

<h2>5. Учётная запись и использование Сервиса</h2>
<ul>
<li><strong>Данные учётной записи:</strong> имя, адрес электронной почты, пароль (хранится только в виде хэша), язык, часовой пояс, название организации, секрет приложения-аутентификатора (в зашифрованном виде), приглашения участников команды (адрес электронной почты и роль).</li>
<li><strong>Вход и безопасность:</strong> запись о сеансе с IP-адресом и сведениями о браузере, пока вы находитесь в системе; сеанс завершается через два часа бездействия. Действия в учётной записи записываются в журнал аудита (кто, что, когда); записи об администрировании оператором хранятся два года.</li>
<li><strong>Согласие с документами:</strong> какую версию этих документов и когда вы приняли (без IP-адреса).</li>
<li><strong>Уведомления:</strong> адреса электронной почты и, если вы подключите Telegram, чат Telegram, в который приходят оповещения.</li>
</ul>
<p>Правовое основание: ст. 6(1)(b) GDPR (предоставление Сервиса по договору) и ст. 6(1)(f) GDPR (безопасность Сервиса, подтверждение согласия). Данные учётной записи мы храним, пока существует учётная запись, и удаляем в течение 30 дней после её закрытия или по вашему запросу, если закон не обязывает их хранить.</p>

<h2>6. Данные ваших магазинов</h2>
<p>Адреса магазинов, ключи подключения (в зашифрованном виде), записи о заказах и оплатах (статус, суммы, валюты, способ оплаты, ссылки на транзакции, время), инциденты и снимки страниц витрины, сделанные во время проверок (хранятся 30 дней), мы обрабатываем по поручению вашей организации, как описано в разделе 15 <a href="/:locale/terms">Условий использования</a>. Сервис устроен так, чтобы не собирать имена, адреса и адреса электронной почты ваших покупателей. Браузерные проверки используют только тестовые данные.</p>

<h2>7. Платежи</h2>
<p>Покупку подписок проводит Paddle.com Market Limited, Лондон, Великобритания, как продавец (Merchant of Record). Paddle собирает ваши платёжные и реквизитные данные как самостоятельный ответственный по своей политике конфиденциальности. Мы получаем от Paddle только то, что нужно для привязки подписки к вашей учётной записи: статус подписки, тариф, счета и суммы, идентификатор покупателя в Paddle и адрес электронной почты для счетов. Данные вашей карты мы никогда не видим. Правовое основание: ст. 6(1)(b) и (c) GDPR; платёжные документы мы храним столько, сколько требует налоговое и коммерческое законодательство.</p>

<h2>8. Получатели и обработчики</h2>
<ul>
<li>Contabo GmbH, Мюнхен, Германия — серверы и зашифрованное хранилище резервных копий (обработчик).</li>
<li>Paddle.com Market Limited, Великобритания — продажа и оплата подписок (самостоятельный ответственный; для Великобритании действует решение ЕС об адекватности защиты данных).</li>
<li>Telegram — только если вы подключите чат Telegram для оповещений; текст оповещения передаётся на серверы Telegram.</li>
<li>Магазины и платёжные провайдеры, которые вы подключаете (например, WooCommerce, Shopware, Stripe, PayPal), — мы читаем там данные по вашему указанию и не передаём им ваши персональные данные.</li>
</ul>
<p>Письма мы отправляем со своего почтового сервера; инструменты рассылок и отслеживания писем не используем. Мы не продаём персональные данные.</p>

<h2>9. Резервные копии</h2>
<p>База данных и почтовые ящики ежедневно копируются, шифруются и хранятся в Германии. Копии хранятся по графику: все копии текущей недели, последняя копия каждой прошлой недели текущего месяца и последняя копия каждого месяца в течение двенадцати месяцев. Поэтому удалённые данные исчезают из резервных копий не позднее этого срока.</p>

<h2>10. Cookie</h2>
<p>Учётная запись клиента и страницы сайта с формой (обратная связь, проверка магазина, регистрация) используют только технически необходимые cookie: cookie сеанса и cookie защиты от подделки межсайтовых запросов. Они нужны для работы запрошенной вами услуги (§ 25(2) TDDDG, ст. 6(1)(b) GDPR) и истекают вместе с сеансом. Когда вы открываете шаг оплаты, форма оплаты Paddle загружается с серверов Paddle и может установить cookie, нужные Paddle для платежа, по политике конфиденциальности Paddle. Баннер согласия не нужен, потому что других cookie мы не используем.</p>

<h2>11. Ваши права</h2>
<p>Вы вправе получить доступ к своим данным (ст. 15 GDPR), исправить их (ст. 16), удалить (ст. 17), ограничить обработку (ст. 18), получить их в переносимом виде (ст. 20) и возразить против обработки на основании законных интересов (ст. 21). Согласие можно отозвать в любой момент на будущее. Пишите на <a href="mailto::contact_email">:contact_email</a>. Вы также вправе подать жалобу в надзорный орган по защите данных, в частности в государстве ЕС, где вы живёте или работаете (ст. 77 GDPR).</p>

<h2>12. Безопасность</h2>
<p>Мы используем зашифрованные соединения (TLS), храним ключи и секреты в зашифрованном виде, защищаем административный доступ двухфакторной аутентификацией, разделяем данные организаций и шифруем резервные копии.</p>

<h2>13. Автоматизированные решения</h2>
<p>Мы не принимаем решений исключительно на основе автоматизированной обработки, которые порождают для вас юридические или столь же существенные последствия, и не используем искусственный интеллект для обработки ваших данных.</p>

<h2>14. Изменения</h2>
<p>Мы обновляем эту политику при изменении Сервиса или законодательства. Актуальная версия всегда доступна на этой странице; о существенных изменениях владельцев учётных записей мы уведомляем по электронной почте.</p>
HTML,
        ],
    ],
];
