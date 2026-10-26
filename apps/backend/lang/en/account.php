<?php

return [
    'support_access_requested' => [
        'subject' => 'Business Watchdog: support access request for your store',
        'body' => "Staff member :staff requested access to store :store in organisation :tenant for case :case. No store data is available to them until you approve. Review the request:\n:link\n\nIf you did not expect this request, deny it in your account.",
    ],
    'support_incident_acknowledged' => [
        'subject' => 'Business Watchdog: support acknowledged an incident',
        'body' => "Support acknowledged incident :incident for “:tenant”. The action is recorded in your organisation history. Review it here:\n:link",
    ],
    'support_incident_resolved' => [
        'subject' => 'Business Watchdog: support resolved an incident',
        'body' => "Support marked incident :incident for “:tenant” as resolved. The action is recorded in your organisation history. Review it here:\n:link",
    ],
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
        'subject' => 'Business Watchdog: ',
        'body' => "Hello,\n\n\n\nAccount of “:tenant”: :link",
    ],
    'platform_inviter' => 'The Business Watchdog team',
    'staff_invitation' => [
        'subject' => 'Business Watchdog: invitation to the service team',
        'body' => ":inviter invites you to the Business Watchdog control panel with the role “:role”.\n\nTo accept, open the link (valid for :hours h), set a password and connect an authenticator app:\n:link\n\nIf you did not expect this invitation, ignore this email.",
    ],
    'staff_mfa_reset' => [
        'subject' => 'Business Watchdog: connect a new authenticator',
        'body' => "A platform owner reset your authenticator app for the Business Watchdog control panel: old codes no longer work and all sessions are ended.\n\nTo sign in again, open the link (valid for :hours h), enter your password and connect a new authenticator app:\n:link\n\nIf you did not ask for this, tell a platform owner.",
    ],
    'service_alert' => [
        'problem' => [
            'subject' => 'Business Watchdog (:environment): service problem',
            'body' => "Hello,\n\nThe overall state of «Service» became “problem” (:time).\n\nWhat is wrong:\n:parts\n\nDetails: :link\n\nYou will get another e-mail when it recovers.",
        ],
        'recovered' => [
            'subject' => 'Business Watchdog (:environment): service recovered',
            'body' => "Hello,\n\nThe overall state of «Service» is no longer “problem” (:time).\n\nDetails: :link",
        ],
        'parts' => [
            'component_database' => 'database',
            'component_redis' => 'Redis',
            'component_cache' => 'cache',
            'component_keyring' => 'encryption keys',
            'queue_event_inbox' => 'store event intake',
            'queue_domain_outbox' => 'internal event queue',
            'queue_billing_events' => 'Paddle events',
            'queue_reconciliation' => 'reconciliation queue',
            'queue_notifications' => 'customer notifications',
            'queue_jobs' => 'connector sync jobs (queue workers)',
            'browser_checks' => 'browser checks (no workers)',
        ],
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
        'custom' => 'custom permissions',
    ],
    'organization_deletion_requested' => [
        'subject' => 'Business Watchdog: the organization “:tenant” will be deleted on :date',
        'body' => "Deletion of the organization “:tenant” has been requested (:by). Monitoring of its stores has stopped and a paid subscription, if any, is cancelled.\n\nOn :date all data of the organization will be deleted for good: stores, orders, reconciliation, incidents, checks, screenshots, notifications and the team. Only anonymized billing records that we must keep will remain.\n\nUntil then the owner can cancel the deletion in the dashboard:\n:link\n\nIf you did not request this, cancel it right away and change your password.",
    ],
    'organization_deletion_cancelled' => [
        'subject' => 'Business Watchdog: deletion of the organization “:tenant” cancelled',
        'body' => "Deletion of the organization “:tenant” has been cancelled (:by). Monitoring has resumed and the data of the paused period will catch up. The cancelled subscription does not come back: subscribe again under “Subscription” if needed.\n:link",
    ],
    'organization_deletion_completed' => [
        'subject' => 'Business Watchdog: the organization “:tenant” has been deleted',
        'body' => 'The data of the organization “:tenant” has been deleted. Only anonymized billing records that we must keep remain. If your account belongs to no other organization, it has been deleted too.',
    ],
    'organization_deletion_by' => [
        'owner' => 'the organization owner',
        'platform' => 'Business Watchdog support at your request',
    ],
    'greeting' => [
        'mr' => 'Dear Mr :last_name,',
        'ms' => 'Dear Ms :last_name,',
        'neutral' => 'Hello :name,',
    ],
    'permissions' => [
        'stores_read' => 'viewing stores, orders, payments and checks',
        'stores_manage' => 'adding and configuring stores',
        'integrations_manage' => 'connecting stores and payment providers',
        'checks_run' => 'running checks and editing scenarios',
        'reconciliation_read' => 'viewing reconciliation and CSV exports',
        'reconciliation_run' => 'running reconciliation',
        'allocations_manage' => 'manually linking payments and refunds',
        'incidents_read' => 'viewing incidents',
        'incidents_manage' => 'acknowledging, resolving and commenting on incidents',
        'incidents_snooze' => 'snoozing incidents',
        'notifications_manage' => 'notification channels',
        'team_manage' => 'inviting members and setting their permissions',
        'money_view' => 'seeing amounts',
        'billing_manage' => 'subscription and invoices',
        'stores_delete' => 'deleting a store',
        'ownership_transfer' => 'transferring ownership',
        'members_mfa_reset' => 'resetting the MFA of a member',
        'support_access' => 'support access',
        'organization_delete' => 'deleting the organization',
    ],
    'permissions_changed' => [
        'subject' => 'Business Watchdog: your permissions in “:tenant” changed',
        'body' => "Hello,\n\nYour permissions in the Business Watchdog account of “:tenant” were changed. The change is already in effect.\n\nAdded:\n:added\n\nRemoved:\n:removed\n\nMembers and permissions: :link\n\nIf you did not expect this, contact the organization owner.",
        'none' => '— none',
    ],
    'email_change_requested' => [
        'subject' => 'Business Watchdog: change of e-mail address requested',
        'body' => "Hello,\n\nA change of the sign-in address of your Business Watchdog account to :email was requested. The address changes only after it is confirmed with the link sent to the new address; until then, sign in with this address.\n\nIf this was not you, open the link — the change is cancelled and every session ends:\n:link\n\nWe then recommend changing your password with “Forgot your password?” on the sign-in page.",
    ],
    'email_change_confirm' => [
        'subject' => 'Business Watchdog: confirm your new e-mail address',
        'body' => "Hello,\n\nThis address was entered as the new sign-in address for Business Watchdog. To confirm the change, open the link (valid for :hours hours):\n:link\n\nAfter confirming, every session ends — sign in again with this address. If you did not request a change, simply ignore this email.",
    ],
    'email_change_address_taken' => [
        'subject' => 'Business Watchdog: this address is already in use',
        'body' => "Hello,\n\nSomeone wanted to use this address as the sign-in address of another Business Watchdog account, but an account already uses it, so the change is not possible. Nothing changed.\n\nIf this is your account and you forgot the password, reset it:\n:link\n\nIf you did not request anything, simply ignore this email.",
    ],
    'email_changed' => [
        'subject' => 'Business Watchdog: e-mail address changed',
        'body' => "Hello,\n\nThe sign-in address of your Business Watchdog account was changed to :email. Every session has ended. Account emails now go to the new address.\n\nIf this was not you, contact support at once by replying to this email.",
    ],
];
