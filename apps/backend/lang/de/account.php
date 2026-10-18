<?php

return [
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
        'subject' => 'Business Watchdog: :subject',
        'body' => "Hallo,\n\n:message\n\nKundenkonto von „:tenant“: :link",
    ],
    'platform_inviter' => 'Das Business-Watchdog-Team',
    'staff_invitation' => [
        'subject' => 'Business Watchdog: Einladung ins Team des Dienstes',
        'body' => ":inviter lädt Sie in die Verwaltung von Business Watchdog mit der Rolle „:role“ ein.\n\nZum Annehmen öffnen Sie den Link (gültig :hours h), legen ein Passwort fest und verbinden eine Authenticator-App:\n:link\n\nWenn Sie diese Einladung nicht erwartet haben, ignorieren Sie diese E-Mail.",
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
    ],
];
