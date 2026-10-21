<?php

return [
    'slug' => 'terms',
    'policy_type' => 'service_terms',
    'sort' => 10,
    'locales' => [
        'en' => [
            'title' => 'Terms of Service',
            'description' => 'Terms for using Business Watchdog, the monitoring service for online stores.',
            'body' => <<<'HTML'
<p>Effective date: :effective_date</p>

<h2>1. Who we are</h2>
<p>Business Watchdog (business-watchdog.com, the “Service”) is provided by <strong>:seller_name</strong>, a private individual, :seller_address (“we”, “us”). Contact: <a href="mailto::contact_email">:contact_email</a>.</p>

<h2>2. Scope and business customers only</h2>
<p>These Terms govern the use of the Service by the organisation that registers an account and by the people it invites (“you”). The Service is offered exclusively to businesses — persons acting in their trade, business or profession. By registering you confirm that you use the Service for business purposes. Conflicting terms of yours do not apply.</p>

<h2>3. The Service</h2>
<p>The Service monitors online stores: it runs automated browser checks of the storefront and checkout up to the configured step (it never places a real order and never charges or refunds money), compares the orders and payments reported by your store with the data of the payment providers you connect (read-only), opens incidents and notifies you. The scope of the features depends on your plan, as shown on the pricing page.</p>
<p>The Service observes and reports. It does not process payments, does not change data in your store or at your payment providers and does not replace your own controls. Results depend on the data that the connected systems provide; where data is missing or outdated the Service shows it as unknown. We do not guarantee that every problem is detected or detected in time.</p>

<h2>4. Your account</h2>
<p>You provide accurate information, keep your credentials and second-factor codes secret and are responsible for the actions of the team members you invite. Tell us without delay if you suspect misuse of your account.</p>

<h2>5. Your obligations and acceptable use</h2>
<ul>
<li>Connect only stores, accounts and keys that you are entitled to use; use read-only keys where the Service asks for them.</li>
<li>Do not use the Service to check third-party stores without their permission, to attack, overload or probe systems, to circumvent limits or security measures, or for unlawful purposes.</li>
<li>Do not copy, resell or reverse engineer the Service except where the law expressly allows it.</li>
</ul>
<p>We may suspend access in case of a serious breach or a threat to the security of the Service; we inform you and restore access once the reason is removed.</p>

<h2>6. Your data and data processing</h2>
<p>You remain the owner of the data from your stores and connected accounts (“Customer Data”). We use Customer Data only to provide the Service to you. Where Customer Data contains personal data, we process it on your behalf as processor under Art. 28 GDPR according to Section 15. How we process the personal data of account users and site visitors is described in our <a href="/:locale/privacy">Privacy Policy</a>.</p>
<p>For the visitors of your store you are the sole controller. You alone are responsible for informing them under Art. 13 GDPR about the Service, in particular in your privacy policy, including when you use the checkout observation of the plugin or the app. Texts we provide for your privacy policy or for documenting your legitimate interest are samples for your information only; they are not legal advice and you must check them yourself.</p>

<h2>7. Plans, prices and payment</h2>
<p>Our order process is conducted by our online reseller Paddle.com. Paddle.com is the Merchant of Record for all our orders. Paddle provides all customer service inquiries and handles returns. The prices of the plans are shown on the <a href="/:locale/pricing">pricing page</a>; Paddle calculates and adds taxes where applicable. Subscriptions renew automatically. Billing periods, cancellation, plan changes and refunds are governed by our <a href="/:locale/refund-policy">Subscription and Refund Policy</a> and by Paddle’s buyer terms.</p>

<h2>8. Availability and changes of the Service</h2>
<p>We make reasonable efforts to keep the Service available, but we do not promise uninterrupted availability unless agreed separately. Planned maintenance is announced in advance on the website and in your account; during maintenance scheduled checks and notifications may pause. We develop the Service further and may change features; if a change materially reduces a paid feature you use, we inform you at least 30 days in advance and you may cancel your subscription before it takes effect.</p>

<h2>9. Liability</h2>
<p>We are liable without limitation for intent and gross negligence, for injury to life, body or health and where mandatory law provides for liability. For slight negligence we are liable only for the breach of an obligation whose fulfilment is essential for the proper performance of the contract and on which you may regularly rely, and limited to the damage typical and foreseeable at the time the contract was concluded. In that case our total liability per contract year is limited to the fees you paid for the Service in the twelve months before the event. We are not liable for lost profits, lost sales or for losses resulting from problems in your store or at third parties that the Service did not detect or reported late.</p>

<h2>10. Indemnity</h2>
<p>You indemnify us against claims of third parties arising from your unlawful use of the Service, in particular from connecting stores, accounts or data without being entitled to do so, including reasonable costs of legal defence. This includes claims of third parties and fines of supervisory authorities arising from your not informing the visitors of your store about the Service, or not informing them correctly, in your privacy policy. You are informed of such a claim without delay and may take part in the defence.</p>

<h2>11. Term and termination</h2>
<p>The contract runs for an indefinite period; paid plans run for the billing period you chose and renew automatically. You can cancel at any time in your account with effect from the end of the current billing period. Either party may terminate for good cause without notice, in particular in case of a material breach that is not remedied within a reasonable time after notice. We may end a free account with 30 days’ notice.</p>
<p>When the contract ends, access to the account ends. Until then you can export your data as CSV in your account. We delete Customer Data within 30 days after the end of the contract, unless we must keep it by law; encrypted backups are overwritten according to their retention schedule (Privacy Policy).</p>

<h2>12. Force majeure</h2>
<p>Neither party is liable for delays or failures caused by events beyond its reasonable control, such as natural disasters, war, acts of authorities, power or network outages of third parties or failures of hosting providers. The affected party informs the other party and resumes performance as soon as possible.</p>

<h2>13. Assignment</h2>
<p>You may transfer rights and obligations under this contract only with our prior written consent. We may transfer the contract to a successor who continues the Service; we inform you in advance and you may then terminate the contract.</p>

<h2>14. Changes to these Terms</h2>
<p>We may change these Terms for a valid reason, such as a change of the law or of the Service. We send the new version to the email address of the account at least 30 days before it takes effect. If you object or cancel before that date, the old Terms apply until the end of your current billing period.</p>

<h2>15. Data processing on your behalf (Art. 28 GDPR)</h2>
<ul>
<li><strong>Subject and duration:</strong> providing the Service for the term of the contract.</li>
<li><strong>Nature and purpose:</strong> collecting, storing, comparing and displaying data of your stores and payment accounts, running browser checks, sending notifications.</li>
<li><strong>Categories of data and data subjects:</strong> order and payment records (status, amounts, currencies, payment method, transaction references, timestamps), store and integration settings, screenshots of storefront pages taken during checks, notification addresses and, when you use checkout observation, technical metrics of your checkout pages (page type, stages reached, number of script errors, time, a random technical ID of a report; no IP addresses, form contents or visitor identifiers); data subjects are your team members and, to the limited extent such data reaches the Service, your customers. The Service is built not to collect your customers’ names, addresses or email addresses.</li>
<li><strong>Retention of checkout observation data:</strong> in a Shopware store the reports stay in the store until the Service collects them (usually up to 5 minutes); the Service keeps the random report IDs for 7 days and the aggregated counts until the store is removed or the contract ends (Section 11).</li>
<li><strong>Instructions:</strong> we process the data only on your documented instructions, which are these Terms and your settings in the Service.</li>
<li><strong>Confidentiality and security:</strong> persons with access are bound to confidentiality; we apply appropriate technical and organisational measures (encryption in transit, encrypted storage of keys and secrets, access control with two-factor authentication, tenant separation, encrypted backups; the Shopware app’s access writes and deletes only in the app’s own two entities, and checkout observation collects no IP addresses or form data).</li>
<li><strong>Sub-processors:</strong> Contabo GmbH, Munich, Germany (servers and backup storage in Germany). We inform you in advance of a new sub-processor; you may object for an important reason.</li>
<li><strong>Assistance:</strong> we support you in answering data subject requests and in your obligations under Art. 32–36 GDPR and inform you without undue delay of a personal data breach.</li>
<li><strong>Deletion:</strong> after the end of the contract we delete the data as described in Section 11.</li>
<li><strong>Audits:</strong> we provide the information necessary to demonstrate compliance and allow reasonable audits with prior notice.</li>
</ul>

<h2>16. Governing law and disputes</h2>
<p>This contract is governed by the law of Ukraine, excluding the UN Convention on Contracts for the International Sale of Goods; mandatory provisions of the law of your country that cannot be excluded by contract remain unaffected. The parties first try to settle a dispute amicably by writing to each other. Otherwise the courts of Ukraine competent for our place of residence have jurisdiction.</p>

<h2>17. Final provisions</h2>
<p>These Terms are available in English, German and Russian; in case of differences the English version prevails. If a provision is invalid, the remaining provisions remain in force.</p>
HTML,
        ],
        'de' => [
            'title' => 'Nutzungsbedingungen',
            'description' => 'Bedingungen für die Nutzung von Business Watchdog, dem Überwachungsdienst für Onlineshops.',
            'body' => <<<'HTML'
<p>Stand: :effective_date</p>

<h2>1. Anbieter</h2>
<p>Business Watchdog (business-watchdog.com, der „Dienst“) wird angeboten von <strong>:seller_name</strong>, Privatperson, :seller_address („wir“, „uns“). Kontakt: <a href="mailto::contact_email">:contact_email</a>. Weitere Angaben im <a href="/:locale/imprint">Impressum</a>.</p>

<h2>2. Geltungsbereich, nur für Unternehmer</h2>
<p>Diese Bedingungen gelten für die Nutzung des Dienstes durch die Organisation, die ein Konto registriert, und die von ihr eingeladenen Personen („Sie“). Der Dienst richtet sich ausschließlich an Unternehmer im Sinne von § 14 BGB, also an Personen, die in Ausübung ihrer gewerblichen oder selbständigen beruflichen Tätigkeit handeln. Mit der Registrierung bestätigen Sie, den Dienst zu diesem Zweck zu nutzen. Abweichende Bedingungen von Ihnen gelten nicht.</p>

<h2>3. Leistungen</h2>
<p>Der Dienst überwacht Onlineshops: Er führt automatisierte Browserprüfungen von Shop und Checkout bis zum eingestellten Schritt durch (es wird nie eine echte Bestellung aufgegeben und nie Geld belastet oder erstattet), vergleicht die von Ihrem Shop gemeldeten Bestellungen und Zahlungen mit den Daten der verbundenen Zahlungsanbieter (nur lesend), eröffnet Vorfälle und benachrichtigt Sie. Der Funktionsumfang richtet sich nach Ihrem Tarif laut Preisseite.</p>
<p>Der Dienst beobachtet und meldet. Er wickelt keine Zahlungen ab, ändert keine Daten in Ihrem Shop oder bei Ihren Zahlungsanbietern und ersetzt nicht Ihre eigenen Kontrollen. Die Ergebnisse hängen von den Daten der verbundenen Systeme ab; fehlende oder veraltete Daten zeigt der Dienst als unbekannt an. Wir garantieren nicht, dass jedes Problem oder jedes Problem rechtzeitig erkannt wird.</p>

<h2>4. Ihr Konto</h2>
<p>Sie machen zutreffende Angaben, halten Zugangsdaten und Codes des zweiten Faktors geheim und sind für die Handlungen der von Ihnen eingeladenen Teammitglieder verantwortlich. Bei Verdacht auf Missbrauch informieren Sie uns unverzüglich.</p>

<h2>5. Ihre Pflichten und zulässige Nutzung</h2>
<ul>
<li>Verbinden Sie nur Shops, Konten und Schlüssel, zu deren Nutzung Sie berechtigt sind; verwenden Sie Nur-Lese-Schlüssel, wo der Dienst sie verlangt.</li>
<li>Nutzen Sie den Dienst nicht, um fremde Shops ohne deren Erlaubnis zu prüfen, Systeme anzugreifen, zu überlasten oder auszuspähen, Limits oder Sicherheitsmaßnahmen zu umgehen oder zu rechtswidrigen Zwecken.</li>
<li>Vervielfältigen, weiterverkaufen oder dekompilieren Sie den Dienst nicht, soweit das Gesetz es nicht ausdrücklich erlaubt.</li>
</ul>
<p>Bei einem schwerwiegenden Verstoß oder einer Gefahr für die Sicherheit des Dienstes können wir den Zugang sperren; wir informieren Sie und heben die Sperre auf, sobald der Grund entfallen ist.</p>

<h2>6. Ihre Daten und Auftragsverarbeitung</h2>
<p>Die Daten Ihrer Shops und verbundenen Konten („Kundendaten“) bleiben Ihre Daten. Wir nutzen Kundendaten nur, um Ihnen den Dienst zu erbringen. Soweit Kundendaten personenbezogene Daten enthalten, verarbeiten wir sie in Ihrem Auftrag als Auftragsverarbeiter nach Art. 28 DSGVO gemäß Ziffer 15. Wie wir personenbezogene Daten von Kontonutzern und Website-Besuchern verarbeiten, beschreibt unsere <a href="/:locale/privacy">Datenschutzerklärung</a>.</p>
<p>Gegenüber den Besuchern Ihres Shops sind Sie allein Verantwortlicher. Sie allein sind dafür verantwortlich, diese nach Art. 13 DSGVO über den Dienst zu informieren, insbesondere in Ihrer Datenschutzerklärung, auch wenn Sie die Beobachtung des Bestellabschlusses durch das Plugin oder die App nutzen. Von uns bereitgestellte Texte für Ihre Datenschutzerklärung oder zur Dokumentation Ihres berechtigten Interesses sind unverbindliche Muster; sie sind keine Rechtsberatung und von Ihnen selbst zu prüfen.</p>

<h2>7. Tarife, Preise und Zahlung</h2>
<p>Unser Bestellprozess wird von unserem Online-Wiederverkäufer Paddle.com durchgeführt. Paddle.com ist der Merchant of Record (Verkäufer) für alle unsere Bestellungen. Paddle bearbeitet alle Kundenanfragen zu Bestellungen und wickelt Erstattungen ab. Die Preise der Tarife stehen auf der <a href="/:locale/pricing">Preisseite</a>; Paddle berechnet und erhebt gegebenenfalls die Steuern. Abonnements verlängern sich automatisch. Abrechnungszeiträume, Kündigung, Tarifwechsel und Erstattungen regeln unsere <a href="/:locale/refund-policy">Abonnement- und Erstattungsbedingungen</a> sowie die Käuferbedingungen von Paddle.</p>

<h2>8. Verfügbarkeit und Änderungen des Dienstes</h2>
<p>Wir bemühen uns angemessen um die Verfügbarkeit des Dienstes, sagen aber keine ununterbrochene Verfügbarkeit zu, sofern nicht gesondert vereinbart. Geplante Wartungen kündigen wir vorab auf der Website und in Ihrem Konto an; währenddessen können geplante Prüfungen und Benachrichtigungen pausieren. Wir entwickeln den Dienst weiter und können Funktionen ändern; schränkt eine Änderung eine von Ihnen genutzte kostenpflichtige Funktion wesentlich ein, informieren wir Sie mindestens 30 Tage vorher und Sie können Ihr Abonnement vor dem Wirksamwerden kündigen.</p>

<h2>9. Haftung</h2>
<p>Wir haften unbeschränkt bei Vorsatz und grober Fahrlässigkeit, bei Verletzung von Leben, Körper oder Gesundheit sowie nach zwingenden gesetzlichen Vorschriften. Bei leichter Fahrlässigkeit haften wir nur für die Verletzung einer Pflicht, deren Erfüllung die ordnungsgemäße Durchführung des Vertrags überhaupt erst ermöglicht und auf deren Einhaltung Sie regelmäßig vertrauen dürfen (Kardinalpflicht), und begrenzt auf den bei Vertragsschluss typischen und vorhersehbaren Schaden. In diesem Fall ist unsere Haftung je Vertragsjahr auf die Entgelte begrenzt, die Sie in den zwölf Monaten vor dem Ereignis für den Dienst gezahlt haben. Für entgangenen Gewinn, entgangene Umsätze sowie für Schäden aus Problemen in Ihrem Shop oder bei Dritten, die der Dienst nicht oder verspätet erkannt hat, haften wir nicht.</p>

<h2>10. Freistellung</h2>
<p>Sie stellen uns von Ansprüchen Dritter frei, die aus Ihrer rechtswidrigen Nutzung des Dienstes entstehen, insbesondere aus der Verbindung von Shops, Konten oder Daten ohne Berechtigung, einschließlich angemessener Kosten der Rechtsverteidigung. Dies umfasst Ansprüche Dritter und Bußgelder von Aufsichtsbehörden, die daraus entstehen, dass Sie die Besucher Ihres Shops in Ihrer Datenschutzerklärung nicht oder nicht zutreffend über den Dienst informieren. Wir informieren Sie unverzüglich über einen solchen Anspruch; Sie können sich an der Verteidigung beteiligen.</p>

<h2>11. Laufzeit und Kündigung</h2>
<p>Der Vertrag läuft auf unbestimmte Zeit; kostenpflichtige Tarife laufen für den gewählten Abrechnungszeitraum und verlängern sich automatisch. Sie können jederzeit in Ihrem Konto zum Ende des laufenden Abrechnungszeitraums kündigen. Jede Partei kann aus wichtigem Grund fristlos kündigen, insbesondere bei einem wesentlichen Verstoß, der nach Hinweis nicht in angemessener Frist behoben wird. Ein kostenloses Konto können wir mit einer Frist von 30 Tagen beenden.</p>
<p>Mit Vertragsende endet der Zugang zum Konto. Bis dahin können Sie Ihre Daten im Konto als CSV exportieren. Kundendaten löschen wir innerhalb von 30 Tagen nach Vertragsende, soweit keine gesetzliche Aufbewahrungspflicht besteht; verschlüsselte Sicherungen werden nach ihrem Aufbewahrungsplan überschrieben (Datenschutzerklärung).</p>

<h2>12. Höhere Gewalt</h2>
<p>Keine Partei haftet für Verzögerungen oder Ausfälle durch Ereignisse außerhalb ihrer zumutbaren Kontrolle, etwa Naturkatastrophen, Krieg, behördliche Maßnahmen, Strom- oder Netzausfälle Dritter oder Ausfälle von Hosting-Anbietern. Die betroffene Partei informiert die andere und nimmt die Leistung so bald wie möglich wieder auf.</p>

<h2>13. Übertragung</h2>
<p>Sie können Rechte und Pflichten aus diesem Vertrag nur mit unserer vorherigen schriftlichen Zustimmung übertragen. Wir können den Vertrag auf einen Nachfolger übertragen, der den Dienst fortführt; wir informieren Sie vorab, und Sie können den Vertrag dann kündigen.</p>

<h2>14. Änderungen dieser Bedingungen</h2>
<p>Wir können diese Bedingungen aus triftigem Grund ändern, etwa bei Gesetzesänderungen oder Änderungen des Dienstes. Die neue Fassung senden wir mindestens 30 Tage vor dem Wirksamwerden an die E-Mail-Adresse des Kontos. Widersprechen oder kündigen Sie vor diesem Datum, gelten die bisherigen Bedingungen bis zum Ende Ihres laufenden Abrechnungszeitraums.</p>

<h2>15. Auftragsverarbeitung (Art. 28 DSGVO)</h2>
<ul>
<li><strong>Gegenstand und Dauer:</strong> Erbringung des Dienstes für die Laufzeit des Vertrags.</li>
<li><strong>Art und Zweck:</strong> Erheben, Speichern, Abgleichen und Anzeigen von Daten Ihrer Shops und Zahlungskonten, Durchführung von Browserprüfungen, Versand von Benachrichtigungen.</li>
<li><strong>Datenarten und Betroffene:</strong> Bestell- und Zahlungsdatensätze (Status, Beträge, Währungen, Zahlungsart, Transaktionsreferenzen, Zeitpunkte), Shop- und Integrationseinstellungen, bei Prüfungen erstellte Screenshots von Shopseiten, Benachrichtigungsadressen und, wenn Sie die Beobachtung des Bestellabschlusses nutzen, technische Messwerte Ihrer Checkout-Seiten (Seitentyp, erreichte Schritte, Anzahl der Skriptfehler, Zeitpunkt, zufällige technische ID eines Berichts; keine IP-Adressen, Formularinhalte oder Besucherkennungen); Betroffene sind Ihre Teammitglieder und, soweit solche Daten den Dienst überhaupt erreichen, Ihre Kunden. Der Dienst ist so gebaut, dass er Namen, Anschriften und E-Mail-Adressen Ihrer Kunden nicht erhebt.</li>
<li><strong>Speicherdauer der Beobachtungsdaten:</strong> In einem Shopware-Shop bleiben die Berichte im Shop, bis der Dienst sie abruft (in der Regel bis zu 5 Minuten); der Dienst speichert die zufälligen Berichts-IDs 7 Tage und die zusammengefassten Zählwerte, bis der Shop entfernt wird oder der Vertrag endet (Ziffer 11).</li>
<li><strong>Weisungen:</strong> Wir verarbeiten die Daten nur auf Ihre dokumentierte Weisung; diese besteht aus diesen Bedingungen und Ihren Einstellungen im Dienst.</li>
<li><strong>Vertraulichkeit und Sicherheit:</strong> Personen mit Zugriff sind zur Vertraulichkeit verpflichtet; wir treffen geeignete technische und organisatorische Maßnahmen (Transportverschlüsselung, verschlüsselte Speicherung von Schlüsseln und Geheimnissen, Zugriffskontrolle mit Zwei-Faktor-Authentifizierung, Mandantentrennung, verschlüsselte Sicherungen; der Zugriff der Shopware-App schreibt und löscht nur in den zwei eigenen Entitäten der App, und die Beobachtung des Bestellabschlusses erhebt keine IP-Adressen oder Formulardaten).</li>
<li><strong>Unterauftragsverarbeiter:</strong> Contabo GmbH, München, Deutschland (Server und Sicherungsspeicher in Deutschland). Über einen neuen Unterauftragsverarbeiter informieren wir vorab; Sie können aus wichtigem Grund widersprechen.</li>
<li><strong>Unterstützung:</strong> Wir unterstützen Sie bei Anfragen Betroffener und bei Ihren Pflichten nach Art. 32–36 DSGVO und informieren Sie unverzüglich über eine Verletzung des Schutzes personenbezogener Daten.</li>
<li><strong>Löschung:</strong> Nach Vertragsende löschen wir die Daten wie in Ziffer 11 beschrieben.</li>
<li><strong>Kontrollen:</strong> Wir stellen die zum Nachweis erforderlichen Informationen bereit und ermöglichen angemessene Überprüfungen nach vorheriger Ankündigung.</li>
</ul>

<h2>16. Anwendbares Recht und Streitigkeiten</h2>
<p>Es gilt das Recht der Ukraine unter Ausschluss des UN-Kaufrechts; zwingende Vorschriften des Rechts Ihres Landes, die vertraglich nicht abbedungen werden können, bleiben unberührt. Die Parteien versuchen zunächst, eine Streitigkeit schriftlich gütlich beizulegen. Andernfalls sind die für unseren Wohnsitz zuständigen Gerichte der Ukraine zuständig.</p>

<h2>17. Schlussbestimmungen</h2>
<p>Diese Bedingungen liegen auf Englisch, Deutsch und Russisch vor; bei Abweichungen geht die englische Fassung vor. Ist eine Bestimmung unwirksam, bleiben die übrigen Bestimmungen wirksam.</p>
HTML,
        ],
        'ru' => [
            'title' => 'Условия использования',
            'description' => 'Условия использования Business Watchdog — сервиса мониторинга интернет-магазинов.',
            'body' => <<<'HTML'
<p>Дата вступления в силу: :effective_date</p>

<h2>1. Кто мы</h2>
<p>Сервис Business Watchdog (business-watchdog.com, «Сервис») предоставляет <strong>:seller_name</strong>, частное лицо, :seller_address («мы»). Контакт: <a href="mailto::contact_email">:contact_email</a>.</p>

<h2>2. Сфера действия, только для бизнеса</h2>
<p>Эти Условия регулируют использование Сервиса организацией, зарегистрировавшей учётную запись, и приглашёнными ею людьми («вы»). Сервис предназначен исключительно для бизнеса — лиц, действующих в рамках своей предпринимательской или профессиональной деятельности. Регистрируясь, вы подтверждаете, что используете Сервис в этих целях. Ваши собственные условия, противоречащие этим, не применяются.</p>

<h2>3. Что делает Сервис</h2>
<p>Сервис наблюдает за интернет-магазинами: выполняет автоматические браузерные проверки витрины и оформления заказа до заданного шага (никогда не создаёт настоящий заказ и не списывает и не возвращает деньги), сверяет заказы и оплаты, которые сообщает ваш магазин, с данными подключённых платёжных провайдеров (только чтение), открывает инциденты и уведомляет вас. Набор функций зависит от тарифа, указанного на странице цен.</p>
<p>Сервис наблюдает и сообщает. Он не проводит платежи, не меняет данные в вашем магазине или у платёжных провайдеров и не заменяет ваш собственный контроль. Результаты зависят от данных подключённых систем; если данных нет или они устарели, Сервис показывает их как неизвестные. Мы не гарантируем, что любая проблема будет обнаружена или обнаружена вовремя.</p>

<h2>4. Учётная запись</h2>
<p>Вы указываете достоверные данные, храните в тайне пароли и коды второго фактора и отвечаете за действия приглашённых вами участников команды. О подозрении на злоупотребление учётной записью сообщайте нам без промедления.</p>

<h2>5. Ваши обязанности и допустимое использование</h2>
<ul>
<li>Подключайте только те магазины, учётные записи и ключи, которыми вы вправе пользоваться; используйте ключи только для чтения там, где Сервис их запрашивает.</li>
<li>Не используйте Сервис для проверки чужих магазинов без их разрешения, для атак, перегрузки или зондирования систем, обхода ограничений и защитных мер или в незаконных целях.</li>
<li>Не копируйте, не перепродавайте и не декомпилируйте Сервис, кроме случаев, прямо разрешённых законом.</li>
</ul>
<p>При серьёзном нарушении или угрозе безопасности Сервиса мы можем приостановить доступ; мы сообщим вам об этом и восстановим доступ, когда причина будет устранена.</p>

<h2>6. Ваши данные и их обработка</h2>
<p>Данные ваших магазинов и подключённых учётных записей («Данные клиента») остаются вашими. Мы используем Данные клиента только для предоставления вам Сервиса. Если Данные клиента содержат персональные данные, мы обрабатываем их по вашему поручению как обработчик по ст. 28 GDPR в соответствии с разделом 15. Как мы обрабатываем персональные данные пользователей учётных записей и посетителей сайта, описано в <a href="/:locale/privacy">Политике конфиденциальности</a>.</p>
<p>В отношении посетителей вашего магазина единственным контролёром являетесь вы. Только вы отвечаете за информирование их о Сервисе по ст. 13 GDPR, в частности в вашей политике конфиденциальности, в том числе когда вы используете наблюдение за оформлением заказа в плагине или приложении. Тексты, которые мы предоставляем для вашей политики конфиденциальности или для документирования вашего законного интереса, — лишь справочные образцы; они не являются юридической консультацией, и вы должны проверить их самостоятельно.</p>

<h2>7. Тарифы, цены и оплата</h2>
<p>Оформление заказов выполняет наш онлайн-реселлер Paddle.com. Paddle.com является продавцом (Merchant of Record) по всем нашим заказам. Paddle отвечает на все вопросы покупателей по заказам и оформляет возвраты. Цены тарифов указаны на <a href="/:locale/pricing">странице цен</a>; Paddle рассчитывает и добавляет налоги там, где они применяются. Подписка продлевается автоматически. Периоды оплаты, отмена, смена тарифа и возвраты регулируются нашей <a href="/:locale/refund-policy">Политикой подписки и возвратов</a> и условиями Paddle для покупателей.</p>

<h2>8. Доступность и изменения Сервиса</h2>
<p>Мы прилагаем разумные усилия, чтобы Сервис был доступен, но не обещаем бесперебойной работы, если это не согласовано отдельно. О плановом обслуживании мы заранее сообщаем на сайте и в учётной записи; во время обслуживания плановые проверки и уведомления могут приостанавливаться. Мы развиваем Сервис и можем менять функции; если изменение существенно урезает платную функцию, которой вы пользуетесь, мы сообщим об этом не менее чем за 30 дней, и вы сможете отменить подписку до вступления изменения в силу.</p>

<h2>9. Ответственность</h2>
<p>Мы несём неограниченную ответственность за умысел и грубую неосторожность, за вред жизни и здоровью и там, где ответственность предусмотрена императивными нормами закона. При лёгкой неосторожности мы отвечаем только за нарушение обязанности, исполнение которой необходимо для надлежащего исполнения договора и на которое вы обычно вправе рассчитывать, и только в размере ущерба, типичного и предвидимого на момент заключения договора. В этом случае наша общая ответственность за год договора ограничена суммой, которую вы заплатили за Сервис за двенадцать месяцев до события. Мы не отвечаем за упущенную выгоду, потерянные продажи и убытки от проблем в вашем магазине или у третьих лиц, которые Сервис не обнаружил или о которых сообщил с опозданием.</p>

<h2>10. Освобождение от претензий</h2>
<p>Вы освобождаете нас от претензий третьих лиц, возникших из-за вашего незаконного использования Сервиса, в частности из-за подключения магазинов, учётных записей или данных без соответствующего права, включая разумные расходы на защиту. Это включает претензии третьих лиц и штрафы надзорных органов, возникшие из-за того, что вы не сообщили или неверно сообщили посетителям вашего магазина о Сервисе в своей политике конфиденциальности. Мы без промедления сообщим вам о такой претензии, и вы сможете участвовать в защите.</p>

<h2>11. Срок действия и прекращение</h2>
<p>Договор заключается на неопределённый срок; платные тарифы действуют в течение выбранного периода оплаты и продлеваются автоматически. Вы можете отменить подписку в любой момент в учётной записи с окончанием текущего периода оплаты. Каждая сторона может расторгнуть договор без предупреждения по уважительной причине, в частности при существенном нарушении, не устранённом в разумный срок после уведомления. Бесплатную учётную запись мы можем закрыть, предупредив за 30 дней.</p>
<p>С прекращением договора прекращается доступ к учётной записи. До этого момента вы можете выгрузить свои данные в CSV в учётной записи. Данные клиента мы удаляем в течение 30 дней после прекращения договора, если закон не обязывает их хранить; зашифрованные резервные копии перезаписываются по графику хранения (Политика конфиденциальности).</p>

<h2>12. Форс-мажор</h2>
<p>Ни одна из сторон не отвечает за задержки и сбои, вызванные событиями вне её разумного контроля, — стихийными бедствиями, войной, действиями органов власти, отключениями электроэнергии и сетей третьих лиц, сбоями хостинг-провайдеров. Пострадавшая сторона сообщает другой и возобновляет исполнение как можно скорее.</p>

<h2>13. Передача прав</h2>
<p>Вы можете передать права и обязанности по договору только с нашего предварительного письменного согласия. Мы можем передать договор преемнику, который продолжит работу Сервиса; мы заранее сообщим вам об этом, и вы сможете расторгнуть договор.</p>

<h2>14. Изменение Условий</h2>
<p>Мы можем изменить Условия по обоснованной причине, например при изменении закона или Сервиса. Новую редакцию мы отправляем на адрес электронной почты учётной записи не менее чем за 30 дней до её вступления в силу. Если вы возразите или отмените подписку до этой даты, прежние Условия действуют до конца текущего периода оплаты.</p>

<h2>15. Обработка данных по поручению (ст. 28 GDPR)</h2>
<ul>
<li><strong>Предмет и срок:</strong> предоставление Сервиса на срок действия договора.</li>
<li><strong>Характер и цель:</strong> получение, хранение, сверка и отображение данных ваших магазинов и платёжных учётных записей, выполнение браузерных проверок, отправка уведомлений.</li>
<li><strong>Категории данных и субъектов:</strong> записи о заказах и оплатах (статус, суммы, валюты, способ оплаты, ссылки на транзакции, время), настройки магазинов и интеграций, снимки страниц витрины, сделанные во время проверок, адреса для уведомлений и, если вы используете наблюдение за оформлением заказа, технические показатели страниц оформления (тип страницы, пройденные этапы, число ошибок скрипта, время, случайный технический ID отчёта; без IP-адресов, содержимого форм и идентификаторов посетителей); субъекты — участники вашей команды и, в той мере, в какой такие данные вообще попадают в Сервис, ваши покупатели. Сервис устроен так, чтобы не собирать имена, адреса и адреса электронной почты ваших покупателей.</li>
<li><strong>Сроки хранения данных наблюдения:</strong> в магазине Shopware отчёты хранятся до того, как Сервис их заберёт (обычно до 5 минут); Сервис хранит случайные ID отчётов 7 дней, а сводные счётчики — до удаления магазина или прекращения договора (раздел 11).</li>
<li><strong>Указания:</strong> мы обрабатываем данные только по вашим задокументированным указаниям — этим Условиям и вашим настройкам в Сервисе.</li>
<li><strong>Конфиденциальность и безопасность:</strong> лица с доступом обязаны соблюдать конфиденциальность; мы применяем надлежащие технические и организационные меры (шифрование при передаче, зашифрованное хранение ключей и секретов, контроль доступа с двухфакторной аутентификацией, разделение данных клиентов, зашифрованные резервные копии; доступ приложения Shopware записывает и удаляет данные только в двух собственных сущностях приложения, а наблюдение за оформлением заказа не собирает IP-адреса и данные форм).</li>
<li><strong>Субобработчики:</strong> Contabo GmbH, Мюнхен, Германия (серверы и хранилище резервных копий в Германии). О новом субобработчике мы сообщаем заранее; вы можете возразить по уважительной причине.</li>
<li><strong>Содействие:</strong> мы помогаем вам отвечать на запросы субъектов данных и выполнять обязанности по ст. 32–36 GDPR и без необоснованной задержки сообщаем об утечке персональных данных.</li>
<li><strong>Удаление:</strong> после прекращения договора мы удаляем данные, как описано в разделе 11.</li>
<li><strong>Проверки:</strong> мы предоставляем сведения, необходимые для подтверждения соблюдения требований, и допускаем разумные проверки по предварительному уведомлению.</li>
</ul>

<h2>16. Применимое право и споры</h2>
<p>К договору применяется право Украины, за исключением Венской конвенции ООН о договорах международной купли-продажи товаров; императивные нормы права вашей страны, которые нельзя исключить договором, сохраняют силу. Стороны сначала пытаются урегулировать спор мирно в письменной форме. В противном случае спор рассматривают суды Украины по месту нашего проживания.</p>

<h2>17. Заключительные положения</h2>
<p>Условия доступны на английском, немецком и русском языках; при расхождениях преимущество имеет английская версия. Если какое-либо положение недействительно, остальные положения сохраняют силу.</p>
HTML,
        ],
    ],
];
