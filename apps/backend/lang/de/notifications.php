<?php

return [
    'subject' => [
        'opened' => '[:store] :severity: Abweichung in Zahlungsdaten',
        'reopened' => '[:store] :severity: Abweichung in Zahlungsdaten erneut festgestellt',
        'recovered' => '[:store] Abweichung in Zahlungsdaten behoben',
    ],
    'store' => 'Shop: :store',
    'severity_line' => 'Schweregrad: :severity',
    'severity' => [
        'info' => 'Info',
        'warning' => 'Warnung',
        'critical' => 'Kritisch',
    ],
    'order_unknown' => '(Bestellnummer unbekannt)',
    'unknown' => 'unbekannt',
    'fact' => [
        'MONEY_CAPTURE_MISSING' => 'Bestellung :order ist im Shop als bezahlt markiert, aber keine bestätigte Abbuchung beim verbundenen Zahlungsanbieter ist ihr zugeordnet. Das beweist nicht, dass keine Abbuchung erfolgt ist.',
        'MONEY_CAPTURE_AMOUNT' => 'Der bestätigte Abbuchungsbetrag für Bestellung :order stimmt nicht mit der Bestellsumme überein.',
        'MONEY_REFUND_MISSING' => 'Eine im Shop erfasste Erstattung für Bestellung :order ist vom Zahlungsanbieter nicht bestätigt.',
        'MONEY_REFUND_EXTRA' => 'Der Zahlungsanbieter zeigt eine Erstattung für Bestellung :order, die im Shop fehlt.',
        'MONEY_MULTIPLE_CAPTURES' => 'Für Bestellung :order wurden mehrere unterschiedliche bestätigte Abbuchungen gefunden.',
        'MONEY_CURRENCY_MISMATCH' => 'Die Währung der bestätigten Abbuchung für Bestellung :order stimmt nicht mit der Bestellwährung überein.',
        'MONEY_ORDER_CHANGED' => 'Bestellung :order wurde nach einer bestätigten Abbuchung geändert.',
        'MONEY_PAYMENT_WITHOUT_ORDER' => 'Beim Zahlungsanbieter gibt es eine bestätigte Abbuchung, die keiner Shop-Bestellung zugeordnet ist.',
        'default' => 'Für Bestellung :order wurde eine Abweichung in den Zahlungsdaten festgestellt.',
    ],
    'amount' => 'Abweichung: :amount :currency.',
    'possible_start' => [
        'between' => 'Möglicher Beginn: zwischen :from und :to.',
        'not_later_than' => 'Möglicher Beginn: spätestens :to.',
    ],
    'source' => [
        'reconciliation' => 'Quelle: Abgleich der Shopdaten mit dem verbundenen Zahlungsanbieter.',
    ],
    'checked_steps' => 'Geprüft: :steps.',
    'step' => [
        'store_order_data' => 'Bestelldaten im Shop',
        'provider_transactions' => 'Transaktionen des Zahlungsanbieters',
        'payment_allocations' => 'Zuordnungen von Zahlungen zu Bestellungen',
    ],
    'what_to_check' => [
        'capture' => 'Bitte prüfen: die Transaktion beim Zahlungsanbieter und den Zahlungsstatus der Bestellung.',
        'refund' => 'Bitte prüfen: die Erstattung beim Zahlungsanbieter und im Shop.',
        'payment_without_order' => 'Bitte prüfen: zu welcher Bestellung die Zahlung gehört, und sie bei Bedarf manuell zuordnen.',
        'default' => 'Bitte prüfen: Transaktionen beim Zahlungsanbieter und die Bestellung im Shop.',
    ],
    'link' => 'Vorfall öffnen: :link',
    'recovery' => [
        'fact' => 'Ein neuer Abgleich für Bestellung :order hat keine Abweichung gefunden.',
        'duration' => 'Zeitraum der Abweichung: von :from bis :to.',
        'restored' => 'Wiederhergestellt: Abgleich der Bestellung mit dem Zahlungsanbieter.',
    ],
    'test' => [
        'subject' => 'Business Watchdog: Testbenachrichtigung',
        'body' => 'Dies ist eine Testbenachrichtigung. Kanal „:label“ ist eingerichtet und empfängt Nachrichten.',
    ],
    'verification' => [
        'subject' => 'Business Watchdog: Bestätigungscode',
        'body' => 'Bestätigungscode für den Benachrichtigungskanal: :code. Der Code ist :minutes Minuten gültig.',
    ],
];
