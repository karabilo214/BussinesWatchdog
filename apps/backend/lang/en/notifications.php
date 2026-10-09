<?php

return [
    'subject' => [
        'opened' => '[:store] :severity: payment data discrepancy',
        'reopened' => '[:store] :severity: payment data discrepancy detected again',
        'recovered' => '[:store] Payment data discrepancy resolved',
    ],
    'store' => 'Store: :store',
    'severity_line' => 'Severity: :severity',
    'severity' => [
        'info' => 'Info',
        'warning' => 'Warning',
        'critical' => 'Critical',
    ],
    'order_unknown' => '(order number unknown)',
    'unknown' => 'unknown',
    'fact' => [
        'MONEY_CAPTURE_MISSING' => 'Order :order is marked as paid by the store, but no confirmed capture in the connected payment provider is matched to it. This does not prove that no capture happened.',
        'MONEY_CAPTURE_AMOUNT' => 'The confirmed capture amount for order :order does not match the order total.',
        'MONEY_REFUND_MISSING' => 'A refund for order :order recorded by the store is not confirmed by the payment provider.',
        'MONEY_REFUND_EXTRA' => 'The payment provider shows a refund for order :order that the store does not have.',
        'MONEY_MULTIPLE_CAPTURES' => 'Several distinct confirmed captures were found for order :order.',
        'MONEY_CURRENCY_MISMATCH' => 'The currency of the confirmed capture for order :order does not match the order currency.',
        'MONEY_ORDER_CHANGED' => 'Order :order was changed after a confirmed capture.',
        'MONEY_PAYMENT_WITHOUT_ORDER' => 'The payment provider has a confirmed capture that is not matched to any store order.',
        'CHECKOUT_PAYMENTS_FAILING' => 'The last :count payment attempts with “:method” in a row did not end in a successful payment. This may be a broken payment flow or a series of bank declines — store data cannot always tell them apart.',
        'default' => 'A payment data discrepancy was found for order :order.',
    ],
    'amount' => 'Discrepancy: :amount :currency.',
    'possible_start' => [
        'between' => 'Possible start: between :from and :to.',
        'not_later_than' => 'Possible start: no later than :to.',
    ],
    'source' => [
        'reconciliation' => 'Source: reconciliation of store data against the connected payment provider.',
        'payment_attempts' => 'Source: payment attempts on the store website (store plugin data).',
    ],
    'checked_steps' => 'Checked: :steps.',
    'step' => [
        'store_order_data' => 'store order data',
        'provider_transactions' => 'payment provider transactions',
        'payment_allocations' => 'payment-to-order links',
        'store_checkout_attempts' => 'payment attempts at checkout',
    ],
    'what_to_check' => [
        'capture' => 'What to check: the transaction in the payment provider and the order payment status.',
        'refund' => 'What to check: the refund in the payment provider and in the store.',
        'payment_without_order' => 'What to check: which order the payment belongs to, and link it manually if needed.',
        'payment_method' => 'What to check: place a test order with this payment method, check the payment plugin settings and log, and the payment provider status.',
        'default' => 'What to check: payment provider transactions and the store order.',
    ],
    'link' => 'Open incident: :link',
    'recovery' => [
        'fact' => 'A fresh reconciliation for order :order found no discrepancy.',
        'duration' => 'Discrepancy period: from :from to :to.',
        'restored' => 'Restored: order reconciliation against the payment provider.',
    ],
    'family' => [
        'checkout_payment' => [
            'subject' => [
                'opened' => '[:store] :severity: payments are not going through',
                'reopened' => '[:store] :severity: payments are failing again',
                'recovered' => '[:store] Payments are going through again',
            ],
            'recovery' => [
                'fact' => 'After a series of failed attempts a successful payment with “:method” is observed again.',
                'restored' => 'Restored: successful payments on the store website.',
            ],
        ],
    ],
    'test' => [
        'subject' => 'Business Watchdog: test notification',
        'body' => 'This is a test notification. Channel ":label" is set up and receiving messages.',
    ],
    'verification' => [
        'subject' => 'Business Watchdog: verification code',
        'body' => 'Notification channel verification code: :code. The code is valid for :minutes minutes.',
    ],
];
