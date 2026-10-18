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
    'new_owner_mfa_warning' => [
        'subject' => 'Business Watchdog: turn on MFA to protect your account',
        'body' => "You are now the owner of the “:tenant” team. MFA is off — your account is at serious risk because a password alone is enough to sign in. Set up an authenticator app in your profile settings:\n:link",
    ],
    'mfa_reset' => [
        'subject' => 'Business Watchdog: two-factor authentication was reset',
        'body' => "Two-factor authentication on your Business Watchdog account was reset. All active sessions have ended. Sign in again and set up an authenticator app in your profile settings:\n:link\n\nIf you did not request this, contact support immediately and change your password.",
    ],
    'order_volume_over' => [
        'subject' => 'Business Watchdog: store “:store” has outgrown its package',
        'body' => "Over the last three months the store “:store” has had :average orders a month on average, while your package covers up to :limit orders a month per store.\n\nProtection keeps working as before; nothing is switched off and nothing extra is charged. Please choose a fitting package in your account by :date:\n:link\n\nIf this was a one-off spike and the volume returns within the package, there is nothing to do.",
    ],
    'order_volume_paused' => [
        'subject' => 'Business Watchdog: service paused — order volume above the package',
        'body' => "The grace period has ended and the order volume of the store :stores is still above what your package covers (up to :limit orders a month per store). The organisation's service is therefore paused: checks, reconciliation and notifications are off. Store data is still received; nothing is lost.\n\nWork resumes as soon as a fitting package is bought:\n:link",
    ],
    'reminder_subscription' => [
        'subject' => 'Business Watchdog: a reminder about the subscription of “:tenant”',
        'body' => "Hello,\n\nThis is a reminder about the Business Watchdog subscription of “:tenant”. To keep store checks, reconciliation and notifications running without a break, check the subscription and, if needed, start or renew it in your account:\n:link\n\nIf everything is already in order, simply ignore this email. Questions? Just reply to it.",
    ],
    'reminder_payment' => [
        'subject' => 'Business Watchdog: a payment reminder for “:tenant”',
        'body' => "Hello,\n\nThis is a reminder about the payment for the Business Watchdog subscription of “:tenant”. Please check the payment method and the latest invoices in your account:\n:link\n\nIf the payment has already gone through, simply ignore this email. Questions? Just reply to it.",
    ],
    'reminder_other' => [
        'subject' => 'Business Watchdog: :subject',
        'body' => "Hello,\n\n:message\n\nAccount of “:tenant”: :link",
    ],
    'platform_inviter' => 'The Business Watchdog team',
    'staff_invitation' => [
        'subject' => 'Business Watchdog: invitation to the service team',
        'body' => ":inviter invites you to the Business Watchdog control panel with the role “:role”.\n\nTo accept, open the link (valid for :hours h), set a password and connect an authenticator app:\n:link\n\nIf you did not expect this invitation, ignore this email.",
    ],
    'staff_roles' => [
        'platform_owner' => 'service owner',
        'platform_member' => 'staff member',
        'platform_admin' => 'administrator',
        'platform_support' => 'support',
        'platform_billing' => 'billing',
    ],
    'roles' => [
        'owner' => 'owner',
        'admin' => 'administrator',
        'operator' => 'operator',
        'viewer' => 'viewer',
    ],
];
