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
        'CHECKOUT_PAYMENTS_FAILING' => 'Die letzten :count Zahlungsversuche mit „:method“ in Folge endeten nicht mit einer erfolgreichen Zahlung. Das kann ein defekter Zahlungsablauf oder eine Reihe von Bankablehnungen sein — anhand der Shopdaten lässt sich das nicht immer unterscheiden.',
        'INTEGRATION_STALE' => 'Das Shop-Plugin meldet sich nicht mehr: letztes Signal :last_heartbeat_at. Solange keine Verbindung besteht, kommen keine Bestell- und Zahlungsdaten an; Geld- und Zahlungsprüfungen für diesen Shop ruhen. Das bedeutet nicht, dass der Verkauf gestoppt ist.',
        'INTEGRATION_DELIVERY_DELAYED' => 'Das Shop-Plugin meldet sich, kann Daten aber nicht rechtzeitig übertragen: das älteste nicht gesendete Ereignis stammt von :oldest_pending_at. Bis die Verzögerung behoben ist, ruhen Geld- und Zahlungsprüfungen für diesen Shop. Das bedeutet nicht, dass der Verkauf gestoppt ist.',
        'CHECKOUT_FLOW_FAILED' => 'Die synthetische Prüfung hat das Zahlungsformular zweimal hintereinander nicht erreicht: Schritt „:step“ ist fehlgeschlagen. Die Prüfung legt keine Bestellungen an und bucht kein Geld ab. Bestätigt ist ein Fehler dieses Ablaufs (Produkt → Warenkorb → Kasse → Zahlungsformular), nicht ein Ausfall des ganzen Shops.',
        'CHECKOUT_TEST_PRODUCT_UNAVAILABLE' => 'Das Testprodukt der Checkout-Prüfung ist nicht verfügbar (ausverkauft oder nicht veröffentlicht). Bis es ersetzt ist, wird der Weg zum Zahlungsformular nicht geprüft. Das bedeutet nicht, dass der Shop defekt ist.',
        'CHECKOUT_MONITORING_BLOCKED' => 'Die Website lässt den Prüfbrowser nicht zu (Bot-Schutz, CAPTCHA oder Weiterleitung auf eine nicht erlaubte Adresse). Bis der Zugriff erlaubt ist, wird der Weg zum Zahlungsformular nicht geprüft. CAPTCHAs umgehen wir nicht.',
        'CHECKOUT_ADAPTER_UNSUPPORTED' => 'Die Prüfung konnte die Shopseiten nicht erkennen (individuelles Theme oder Checkout). Das bedeutet nicht, dass der Shop defekt ist, aber der Weg zum Zahlungsformular wird noch nicht geprüft.',
        'default' => 'Für Bestellung :order wurde eine Abweichung in den Zahlungsdaten festgestellt.',
    ],
    'amount' => 'Abweichung: :amount :currency.',
    'possible_start' => [
        'between' => 'Möglicher Beginn: zwischen :from und :to.',
        'not_later_than' => 'Möglicher Beginn: spätestens :to.',
    ],
    'source' => [
        'reconciliation' => 'Quelle: Abgleich der Shopdaten mit dem verbundenen Zahlungsanbieter.',
        'payment_attempts' => 'Quelle: Zahlungsversuche auf der Shop-Website (Daten des Shop-Plugins).',
        'connector_heartbeat' => 'Quelle: Heartbeats und Sendewarteschlange des Shop-Plugins.',
        'browser_check' => 'Quelle: synthetische Browserprüfung des Kundenwegs, ohne Bestellung.',
    ],
    'checked_steps' => 'Geprüft: :steps.',
    'step' => [
        'store_order_data' => 'Bestelldaten im Shop',
        'provider_transactions' => 'Transaktionen des Zahlungsanbieters',
        'payment_allocations' => 'Zuordnungen von Zahlungen zu Bestellungen',
        'store_checkout_attempts' => 'Zahlungsversuche im Checkout',
        'connector_heartbeat' => 'Heartbeat des Shop-Plugins',
        'connector_delivery' => 'Datenübertragung des Shop-Plugins',
        'check_product' => 'Produktseite',
        'check_cart' => 'Warenkorb',
        'check_checkout' => 'Kasse',
        'check_payment_form' => 'Zahlungsformular',
    ],
    'what_to_check' => [
        'capture' => 'Bitte prüfen: die Transaktion beim Zahlungsanbieter und den Zahlungsstatus der Bestellung.',
        'refund' => 'Bitte prüfen: die Erstattung beim Zahlungsanbieter und im Shop.',
        'payment_without_order' => 'Bitte prüfen: zu welcher Bestellung die Zahlung gehört, und sie bei Bedarf manuell zuordnen.',
        'payment_method' => 'Was zu prüfen ist: Testbestellung mit dieser Zahlungsart aufgeben, Einstellungen und Protokoll des Zahlungs-Plugins sowie den Status des Zahlungsanbieters prüfen.',
        'connector_freshness' => 'Was zu prüfen ist: Das Business-Watchdog-Plugin ist aktiv, WP-Cron oder ein System-Cron (Action Scheduler) läuft, die Website ist erreichbar und darf ausgehende Anfragen senden.',
        'payment_form' => 'Was zu prüfen ist: den Weg selbst im Browser gehen — Produkt, Warenkorb, Kasse, Zahlungsart wählen; Fehler auf der Seite und im Website-Protokoll ansehen.',
        'test_product' => 'Was zu prüfen ist: in den Prüfeinstellungen ein vorrätiges Produkt wählen.',
        'monitoring_access' => 'Was zu prüfen ist: Prüfverkehr von Business Watchdog im Bot-Schutz (WAF/CDN) und in den Weiterleitungen erlauben.',
        'adapter' => 'Was zu prüfen ist: Teilen Sie uns mit, welches Theme und welcher Checkout im Shop verwendet werden.',
        'default' => 'Bitte prüfen: Transaktionen beim Zahlungsanbieter und die Bestellung im Shop.',
    ],
    'link' => 'Vorfall öffnen: :link',
    'recovery' => [
        'fact' => 'Ein neuer Abgleich für Bestellung :order hat keine Abweichung gefunden.',
        'duration' => 'Zeitraum der Abweichung: von :from bis :to.',
        'restored' => 'Wiederhergestellt: Abgleich der Bestellung mit dem Zahlungsanbieter.',
    ],
    'family' => [
        'checkout' => [
            'subject' => [
                'opened' => '[:store] :severity: Checkout-Prüfung schlägt fehl',
                'reopened' => '[:store] :severity: Checkout-Prüfung schlägt erneut fehl',
                'recovered' => '[:store] Checkout-Prüfung erfolgreich',
            ],
            'recovery' => [
                'fact' => 'Zwei geplante Prüfungen hintereinander haben das Zahlungsformular erreicht.',
                'restored' => 'Wiederhergestellt: Kundenweg zum Zahlungsformular.',
            ],
            'components' => [
                'test_product' => [
                    'subject' => [
                        'opened' => '[:store] Checkout-Prüfung wird nicht ausgeführt',
                        'reopened' => '[:store] Checkout-Prüfung wird nicht ausgeführt',
                        'recovered' => '[:store] Checkout-Prüfung läuft wieder',
                    ],
                    'recovery' => [
                        'fact' => 'Die Prüfung hat das Zahlungsformular wieder erreicht.',
                        'restored' => 'Wiederhergestellt: Die Checkout-Prüfung läuft.',
                    ],
                ],
                'monitoring_access' => [
                    'subject' => [
                        'opened' => '[:store] Checkout-Prüfung wird nicht ausgeführt',
                        'reopened' => '[:store] Checkout-Prüfung wird nicht ausgeführt',
                        'recovered' => '[:store] Checkout-Prüfung läuft wieder',
                    ],
                    'recovery' => [
                        'fact' => 'Die Prüfung hat das Zahlungsformular wieder erreicht.',
                        'restored' => 'Wiederhergestellt: Die Checkout-Prüfung läuft.',
                    ],
                ],
                'adapter' => [
                    'subject' => [
                        'opened' => '[:store] Checkout-Prüfung wird nicht ausgeführt',
                        'reopened' => '[:store] Checkout-Prüfung wird nicht ausgeführt',
                        'recovered' => '[:store] Checkout-Prüfung läuft wieder',
                    ],
                    'recovery' => [
                        'fact' => 'Die Prüfung hat das Zahlungsformular wieder erreicht.',
                        'restored' => 'Wiederhergestellt: Die Checkout-Prüfung läuft.',
                    ],
                ],
            ],
        ],
        'integration' => [
            'subject' => [
                'opened' => '[:store] :severity: keine Daten vom Shop-Plugin',
                'reopened' => '[:store] :severity: erneut keine Daten vom Shop-Plugin',
                'recovered' => '[:store] Daten vom Shop-Plugin kommen wieder an',
            ],
            'recovery' => [
                'fact' => 'Das Shop-Plugin ist wieder verbunden und überträgt Daten. Prüfungen für den Zeitraum ohne Daten werden neu berechnet.',
                'restored' => 'Wiederhergestellt: Verbindung zum Shop-Plugin.',
            ],
        ],
        'checkout_payment' => [
            'subject' => [
                'opened' => '[:store] :severity: Zahlungen gehen nicht durch',
                'reopened' => '[:store] :severity: Zahlungen schlagen erneut fehl',
                'recovered' => '[:store] Zahlungen gehen wieder durch',
            ],
            'recovery' => [
                'fact' => 'Nach einer Reihe fehlgeschlagener Versuche wird wieder eine erfolgreiche Zahlung mit „:method“ beobachtet.',
                'restored' => 'Wiederhergestellt: erfolgreiche Zahlungen auf der Shop-Website.',
            ],
        ],
    ],
    'check_step' => [
        'product' => 'Produktseite',
        'add_to_cart' => 'In den Warenkorb',
        'cart' => 'Warenkorb',
        'checkout' => 'Kasse',
        'shipping' => 'Adresse und Versand',
        'payment_form' => 'Zahlungsformular',
        'unknown' => 'unbekannter Schritt',
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
