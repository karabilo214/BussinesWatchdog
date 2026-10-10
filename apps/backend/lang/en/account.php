<?php

return [
    'password_reset' => [
        'subject' => 'Business Watchdog: password reset',
        'body' => "Someone asked to reset the password of your Business Watchdog account.\n\nTo set a new password, open this link (valid for :minutes min):\n:link\n\nIf you did not ask for it, ignore this email — your password stays the same.",
    ],
    'email_verification' => [
        'subject' => 'Business Watchdog: confirm your address',
        'body' => "Confirm the email address of your Business Watchdog account by opening this link (valid for :hours h):\n:link\n\nIf you did not sign up, ignore this email.",
    ],
    'invitation' => [
        'subject' => 'Business Watchdog: invitation to the “:tenant” team',
        'body' => ":inviter invites you to the “:tenant” team on Business Watchdog as :role.\n\nTo accept, open this link (valid until :expires):\n:link\n\nIf you were not expecting this, ignore this email.",
    ],
    'roles' => [
        'owner' => 'owner',
        'admin' => 'administrator',
        'operator' => 'operator',
        'viewer' => 'viewer',
    ],
];
