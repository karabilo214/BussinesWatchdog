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
    'order_volume_over' => [
        'subject' => 'Business Watchdog: store “:store” has outgrown its package',
        'body' => "Over the last three months the store “:store” has had :average orders a month on average, while your package covers up to :limit orders a month per store.\n\nProtection keeps working as before; nothing is switched off and nothing extra is charged. Please choose a fitting package in your account by :date:\n:link\n\nIf this was a one-off spike and the volume returns within the package, there is nothing to do.",
    ],
    'order_volume_paused' => [
        'subject' => 'Business Watchdog: service paused — order volume above the package',
        'body' => "The grace period has ended and the order volume of the store :stores is still above what your package covers (up to :limit orders a month per store). The organisation's service is therefore paused: checks, reconciliation and notifications are off. Store data is still received; nothing is lost.\n\nWork resumes as soon as a fitting package is bought:\n:link",
    ],
    'roles' => [
        'owner' => 'owner',
        'admin' => 'administrator',
        'operator' => 'operator',
        'viewer' => 'viewer',
    ],
];
