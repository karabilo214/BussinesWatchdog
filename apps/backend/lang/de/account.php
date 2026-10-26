<?php

return [
    'support_access_requested' => [
        'subject' => 'Business Watchdog: Anfrage auf Support-Zugriff',
        'body' => "Mitarbeiter :staff hat für Vorgang :case Zugriff auf den Shop :store der Organisation :tenant angefragt. Vor Ihrer Freigabe sind die Shop-Daten nicht zugänglich. Anfrage prüfen:\n:link\n\nFalls Sie diese Anfrage nicht erwartet haben, lehnen Sie sie im Kundenkonto ab.",
    ],
    'support_incident_acknowledged' => [
        'subject' => 'Business Watchdog: Support hat einen Vorfall übernommen',
        'body' => "Der Support hat den Vorfall :incident für „:tenant“ übernommen. Die Aktion wurde im Organisationsprotokoll festgehalten. Details:\n:link",
    ],
    'support_incident_resolved' => [
        'subject' => 'Business Watchdog: Support hat einen Vorfall abgeschlossen',
        'body' => "Der Support hat den Vorfall :incident für „:tenant“ als gelöst markiert. Die Aktion wurde im Organisationsprotokoll festgehalten. Details:\n:link",
    ],
    'password_reset' => [
        'subject' => 'Business Watchdog: Passwort zurücksetzen',
        'body' => "Jemand hat das Zurücksetzen des Passworts für Ihr Business-Watchdog-Konto angefordert.\n\nUm ein neues Passwort festzulegen, öffnen Sie diesen Link (gültig :minutes Min.):\n:link\n\nFalls Sie das nicht angefordert haben, ignorieren Sie diese E-Mail – Ihr Passwort bleibt unverändert.",
    ],
    'email_verification' => [
        'subject' => 'Business Watchdog: Adresse bestätigen',
        'body' => "Bestätigen Sie die E-Mail-Adresse Ihres Business-Watchdog-Kontos, indem Sie diesen Link öffnen (gültig :hours Std.):\n:link\n\nFalls Sie sich nicht registriert haben, ignorieren Sie diese E-Mail.",
    ],
    'invitation' => [
        'subject' => 'Business Watchdog: Einladung in das Team „:tenant“',
        'body' => ":inviter lädt Sie in das Team „:tenant“ bei Business Watchdog mit der Rolle „:role“ ein.\n\nZum Annehmen öffnen Sie diesen Link (gültig bis :expires):\n:link\n\nFalls Sie keine Einladung erwartet haben, ignorieren Sie diese E-Mail.",
    ],
    'new_owner_mfa_warning' => [
        'subject' => 'Business Watchdog: Aktivieren Sie MFA zum Schutz Ihres Kontos',
        'body' => "Sie sind jetzt Inhaber des Teams „:tenant“. MFA ist nicht aktiv – Ihr Konto ist stark gefährdet, da zur Anmeldung derzeit Ihr Passwort genügt. Richten Sie eine Authenticator-App in Ihren Profileinstellungen ein:\n:link",
    ],
    'mfa_reset' => [
        'subject' => 'Business Watchdog: Zwei-Faktor-Authentifizierung zurückgesetzt',
        'body' => "Die Zwei-Faktor-Authentifizierung Ihres Business-Watchdog-Kontos wurde zurückgesetzt. Alle aktiven Sitzungen wurden beendet. Melden Sie sich erneut an und richten Sie eine Authenticator-App in Ihren Profileinstellungen ein:\n:link\n\nFalls Sie dies nicht angefordert haben, wenden Sie sich sofort an den Support und ändern Sie Ihr Passwort.",
    ],
    'order_volume_over' => [
        'subject' => 'Business Watchdog: Shop „:store“ ist aus seinem Paket herausgewachsen',
        'body' => "Der Shop „:store“ hatte in den letzten drei Monaten durchschnittlich :average Bestellungen im Monat; Ihr Paket umfasst bis zu :limit Bestellungen im Monat pro Shop.\n\nDer Schutz läuft unverändert weiter; nichts wird abgeschaltet und nichts zusätzlich berechnet. Bitte wählen Sie bis zum :date im Konto ein passendes Paket:\n:link\n\nWar es eine einmalige Spitze und liegt das Volumen wieder im Paket, ist nichts zu tun.",
    ],
    'order_volume_paused' => [
        'subject' => 'Business Watchdog: Dienst pausiert — Bestellvolumen über dem Paket',
        'body' => "Die Kulanzzeit ist abgelaufen und das Bestellvolumen des Shops :stores liegt weiter über Ihrem Paket (bis :limit Bestellungen im Monat pro Shop). Der Dienst der Organisation ist daher pausiert: Prüfungen, Abgleich und Benachrichtigungen ruhen. Shop-Daten werden weiter empfangen, nichts geht verloren.\n\nSobald ein passendes Paket gekauft ist, geht es sofort weiter:\n:link",
    ],
    'reminder_subscription' => [
        'subject' => 'Business Watchdog: Erinnerung an das Abonnement von „:tenant“',
        'body' => "Hallo,\n\nwir erinnern an das Business-Watchdog-Abonnement von „:tenant“. Damit Shop-Prüfungen, Abgleich und Benachrichtigungen ohne Unterbrechung laufen, prüfen Sie bitte das Abonnement und schließen Sie es bei Bedarf im Kundenkonto ab oder verlängern es:\n:link\n\nIst bereits alles in Ordnung, ignorieren Sie diese E-Mail einfach. Fragen? Antworten Sie einfach darauf.",
    ],
    'reminder_payment' => [
        'subject' => 'Business Watchdog: Zahlungserinnerung für „:tenant“',
        'body' => "Hallo,\n\nwir erinnern an die Zahlung für das Business-Watchdog-Abonnement von „:tenant“. Bitte prüfen Sie die Zahlungsmethode und die letzten Rechnungen im Kundenkonto:\n:link\n\nIst die Zahlung bereits erfolgt, ignorieren Sie diese E-Mail einfach. Fragen? Antworten Sie einfach darauf.",
    ],
    'reminder_other' => [
        'subject' => 'Business Watchdog: ',
        'body' => "Hallo,\n\n\n\nKundenkonto von „:tenant“: :link",
    ],
    'platform_inviter' => 'Das Business-Watchdog-Team',
    'staff_invitation' => [
        'subject' => 'Business Watchdog: Einladung ins Team des Dienstes',
        'body' => ":inviter lädt Sie in die Verwaltung von Business Watchdog mit der Rolle „:role“ ein.\n\nZum Annehmen öffnen Sie den Link (gültig :hours h), legen ein Passwort fest und verbinden eine Authenticator-App:\n:link\n\nWenn Sie diese Einladung nicht erwartet haben, ignorieren Sie diese E-Mail.",
    ],
    'staff_mfa_reset' => [
        'subject' => 'Business Watchdog: neuen Authenticator verbinden',
        'body' => "Ein Plattform-Inhaber hat Ihre Authenticator-App für die Verwaltung von Business Watchdog zurückgesetzt: Alte Codes funktionieren nicht mehr, alle Sitzungen sind beendet.\n\nUm sich wieder anzumelden, öffnen Sie den Link (gültig :hours h), geben Ihr Passwort ein und verbinden eine neue Authenticator-App:\n:link\n\nWenn Sie das nicht angefordert haben, informieren Sie einen Plattform-Inhaber.",
    ],
    'service_alert' => [
        'problem' => [
            'subject' => 'Business Watchdog (:environment): Problem im Dienst',
            'body' => "Hallo,\n\nDer Gesamtzustand von «Dienst» ist „Problem“ (:time).\n\nWas nicht stimmt:\n:parts\n\nDetails: :link\n\nSobald alles wieder läuft, kommt eine weitere E-Mail.",
        ],
        'recovered' => [
            'subject' => 'Business Watchdog (:environment): Dienst wieder in Ordnung',
            'body' => "Hallo,\n\nDer Gesamtzustand von «Dienst» ist nicht mehr „Problem“ (:time).\n\nDetails: :link",
        ],
        'parts' => [
            'component_database' => 'Datenbank',
            'component_redis' => 'Redis',
            'component_cache' => 'Cache',
            'component_keyring' => 'Verschlüsselungsschlüssel',
            'queue_event_inbox' => 'Empfang von Shop-Ereignissen',
            'queue_domain_outbox' => 'interne Ereigniswarteschlange',
            'queue_billing_events' => 'Paddle-Ereignisse',
            'queue_reconciliation' => 'Abgleich-Warteschlange',
            'queue_notifications' => 'Kundenbenachrichtigungen',
            'queue_jobs' => 'Synchronisierungsaufgaben (Queue-Worker)',
            'browser_checks' => 'Browserprüfungen (keine Worker)',
        ],
    ],
    'staff_roles' => [
        'platform_owner' => 'Inhaber des Dienstes',
        'platform_member' => 'Mitarbeiter',
        'platform_admin' => 'Administrator',
        'platform_support' => 'Support',
        'platform_billing' => 'Finanzen',
    ],
    'roles' => [
        'owner' => 'Inhaber',
        'admin' => 'Administrator',
        'operator' => 'Operator',
        'viewer' => 'Betrachter',
        'custom' => 'individuelle Rechte',
    ],
    'organization_deletion_requested' => [
        'subject' => 'Business Watchdog: die Organisation „:tenant“ wird am :date gelöscht',
        'body' => "Die Löschung der Organisation „:tenant“ wurde beantragt (:by). Die Überwachung ihrer Shops ist gestoppt, ein kostenpflichtiges Abonnement ist, falls vorhanden, gekündigt.\n\nAm :date werden alle Daten der Organisation endgültig gelöscht: Shops, Bestellungen, Abgleich, Vorfälle, Prüfungen, Screenshots, Benachrichtigungen und das Team. Es bleiben nur anonymisierte Abrechnungsdaten, die wir aufbewahren müssen.\n\nBis dahin kann der Inhaber die Löschung im Dashboard widerrufen:\n:link\n\nWenn Sie das nicht beantragt haben, widerrufen Sie es sofort und ändern Sie Ihr Passwort.",
    ],
    'organization_deletion_cancelled' => [
        'subject' => 'Business Watchdog: Löschung der Organisation „:tenant“ widerrufen',
        'body' => "Die Löschung der Organisation „:tenant“ wurde widerrufen (:by). Die Überwachung läuft wieder, die Daten des pausierten Zeitraums werden nachgeladen. Das gekündigte Abonnement wird nicht wiederhergestellt: schließen Sie es bei Bedarf unter „Abonnement“ neu ab.\n:link",
    ],
    'organization_deletion_completed' => [
        'subject' => 'Business Watchdog: die Organisation „:tenant“ wurde gelöscht',
        'body' => 'Die Daten der Organisation „:tenant“ wurden gelöscht. Es bleiben nur anonymisierte Abrechnungsdaten, die wir aufbewahren müssen. Gehört Ihr Konto keiner anderen Organisation an, wurde es ebenfalls gelöscht.',
    ],
    'organization_deletion_by' => [
        'owner' => 'Inhaber der Organisation',
        'platform' => 'Business-Watchdog-Support auf Ihre Bitte',
    ],
    'greeting' => [
        'mr' => 'Sehr geehrter Herr :last_name,',
        'ms' => 'Sehr geehrte Frau :last_name,',
        'neutral' => 'Guten Tag :name,',
    ],
    'permissions' => [
        'stores_read' => 'Shops, Bestellungen, Zahlungen und Prüfungen ansehen',
        'stores_manage' => 'Shops hinzufügen und einstellen',
        'integrations_manage' => 'Shops und Zahlungsanbieter verbinden',
        'checks_run' => 'Prüfungen starten und Szenarien bearbeiten',
        'reconciliation_read' => 'Abgleich ansehen und CSV exportieren',
        'reconciliation_run' => 'Abgleich starten',
        'allocations_manage' => 'Zahlungen und Erstattungen manuell zuordnen',
        'incidents_read' => 'Vorfälle ansehen',
        'incidents_manage' => 'Vorfälle bestätigen, schließen und kommentieren',
        'incidents_snooze' => 'Vorfälle zurückstellen',
        'notifications_manage' => 'Benachrichtigungskanäle',
        'team_manage' => 'Mitglieder einladen und ihre Rechte festlegen',
        'money_view' => 'Beträge sehen',
        'billing_manage' => 'Abonnement und Rechnungen',
        'stores_delete' => 'Shop löschen',
        'ownership_transfer' => 'Inhaberschaft übertragen',
        'members_mfa_reset' => 'MFA eines Mitglieds zurücksetzen',
        'support_access' => 'Support-Zugriff',
        'organization_delete' => 'Organisation löschen',
    ],
    'permissions_changed' => [
        'subject' => 'Business Watchdog: Ihre Rechte in „:tenant“ wurden geändert',
        'body' => "Hallo,\n\nIhre Rechte im Business-Watchdog-Konto von „:tenant“ wurden geändert. Die Änderung gilt bereits.\n\nHinzugefügt:\n:added\n\nEntfernt:\n:removed\n\nMitglieder und Rechte: :link\n\nFalls Sie das nicht erwartet haben, wenden Sie sich an den Inhaber der Organisation.",
        'none' => '— keine',
    ],
    'email_change_requested' => [
        'subject' => 'Business Watchdog: Änderung der E-Mail-Adresse angefordert',
        'body' => "Hallo,\n\nFür Ihr Business-Watchdog-Konto wurde die Änderung der Anmeldeadresse auf :email angefordert. Die Adresse ändert sich erst, wenn sie über den Link an die neue Adresse bestätigt wird; bis dahin melden Sie sich mit dieser Adresse an.\n\nWenn Sie das nicht waren, öffnen Sie den Link – die Änderung wird abgebrochen und alle Sitzungen enden:\n:link\n\nÄndern Sie danach am besten Ihr Passwort über „Passwort vergessen?“ auf der Anmeldeseite.",
    ],
    'email_change_confirm' => [
        'subject' => 'Business Watchdog: Neue E-Mail-Adresse bestätigen',
        'body' => "Hallo,\n\nDiese Adresse wurde als neue Anmeldeadresse für Business Watchdog angegeben. Zum Bestätigen öffnen Sie den Link (gültig :hours Stunden):\n:link\n\nNach der Bestätigung enden alle Sitzungen – melden Sie sich mit dieser Adresse neu an. Wenn Sie keine Änderung angefordert haben, ignorieren Sie diese E-Mail einfach.",
    ],
    'email_change_address_taken' => [
        'subject' => 'Business Watchdog: Diese Adresse wird bereits verwendet',
        'body' => "Hallo,\n\nJemand wollte diese Adresse als Anmeldeadresse eines anderen Business-Watchdog-Kontos verwenden, doch sie gehört bereits zu einem Konto – die Änderung ist nicht möglich. Es hat sich nichts geändert.\n\nWenn es Ihr Konto ist und Sie das Passwort vergessen haben, setzen Sie es zurück:\n:link\n\nWenn Sie nichts angefordert haben, ignorieren Sie diese E-Mail einfach.",
    ],
    'email_changed' => [
        'subject' => 'Business Watchdog: E-Mail-Adresse geändert',
        'body' => "Hallo,\n\nDie Anmeldeadresse Ihres Business-Watchdog-Kontos wurde auf :email geändert. Alle Sitzungen wurden beendet. Kontobezogene E-Mails gehen jetzt an die neue Adresse.\n\nWenn Sie das nicht waren, antworten Sie bitte sofort auf diese E-Mail an den Support.",
    ],
];
