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
    'order_volume_over' => [
        'subject' => 'Business Watchdog: Shop „:store“ ist aus seinem Paket herausgewachsen',
        'body' => "Der Shop „:store“ hatte in den letzten drei Monaten durchschnittlich :average Bestellungen im Monat; Ihr Paket umfasst bis zu :limit Bestellungen im Monat pro Shop.\n\nDer Schutz läuft unverändert weiter; nichts wird abgeschaltet und nichts zusätzlich berechnet. Bitte wählen Sie bis zum :date im Konto ein passendes Paket:\n:link\n\nWar es eine einmalige Spitze und liegt das Volumen wieder im Paket, ist nichts zu tun.",
    ],
    'roles' => [
        'owner' => 'Inhaber',
        'admin' => 'Administrator',
        'operator' => 'Operator',
        'viewer' => 'Betrachter',
    ],
];
