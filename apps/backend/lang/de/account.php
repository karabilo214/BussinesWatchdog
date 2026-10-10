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
    'roles' => [
        'owner' => 'Inhaber',
        'admin' => 'Administrator',
        'operator' => 'Operator',
        'viewer' => 'Betrachter',
    ],
];
