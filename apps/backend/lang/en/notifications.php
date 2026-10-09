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
        'INTEGRATION_STALE' => 'The store plugin has stopped checking in: last signal :last_heartbeat_at. Until it reconnects no order or payment-attempt data arrives, so money and payment checks for this store are paused. This does not mean sales have stopped.',
        'INTEGRATION_DELIVERY_DELAYED' => 'The store plugin is checking in but cannot deliver data in time: the oldest unsent event is from :oldest_pending_at. Until the delay clears, money and payment checks for this store are paused. This does not mean sales have stopped.',
        'CHECKOUT_FLOW_FAILED' => 'The synthetic check failed twice in a row to reach the payment form: step “:step” did not pass. The check creates no orders and charges no money. This confirms a failure of this specific path (product → cart → checkout → payment form), not that the whole store is down.',
        'CHECKOUT_TEST_PRODUCT_UNAVAILABLE' => 'The test product used by the checkout check is unavailable (out of stock or unpublished). Until it is replaced, the path to the payment form is not checked. This does not mean the store is broken.',
        'CHECKOUT_MONITORING_BLOCKED' => 'The site does not let the check browser in (bot protection, CAPTCHA or a redirect to a non-allowed address). Until access is allowed, the path to the payment form is not checked. We do not bypass CAPTCHAs.',
        'CHECKOUT_ADAPTER_UNSUPPORTED' => 'The check could not recognise the store pages (custom theme or checkout). This does not mean the store is broken, but the path to the payment form is not checked yet.',
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
        'connector_heartbeat' => 'Source: heartbeats and the delivery queue of the store plugin.',
        'browser_check' => 'Source: synthetic browser check of the customer path, without placing an order.',
    ],
    'checked_steps' => 'Checked: :steps.',
    'step' => [
        'store_order_data' => 'store order data',
        'provider_transactions' => 'payment provider transactions',
        'payment_allocations' => 'payment-to-order links',
        'store_checkout_attempts' => 'payment attempts at checkout',
        'connector_heartbeat' => 'store plugin heartbeat',
        'connector_delivery' => 'store plugin data delivery',
        'check_product' => 'product page',
        'check_cart' => 'cart',
        'check_checkout' => 'checkout',
        'check_payment_form' => 'payment form',
    ],
    'what_to_check' => [
        'capture' => 'What to check: the transaction in the payment provider and the order payment status.',
        'refund' => 'What to check: the refund in the payment provider and in the store.',
        'payment_without_order' => 'What to check: which order the payment belongs to, and link it manually if needed.',
        'payment_method' => 'What to check: place a test order with this payment method, check the payment plugin settings and log, and the payment provider status.',
        'connector_freshness' => 'What to check: the Business Watchdog plugin is active, WP-Cron or a system cron (Action Scheduler) runs on the site, and the site is reachable and can make outbound requests.',
        'payment_form' => 'What to check: walk the path in a browser yourself — product, cart, checkout, choosing a payment method; look at errors on the page and in the site log.',
        'test_product' => 'What to check: choose an in-stock product in the check settings.',
        'monitoring_access' => 'What to check: allow Business Watchdog check traffic in your bot protection (WAF/CDN) and redirect settings.',
        'adapter' => 'What to check: tell us which theme and checkout the store uses.',
        'default' => 'What to check: payment provider transactions and the store order.',
    ],
    'link' => 'Open incident: :link',
    'recovery' => [
        'fact' => 'A fresh reconciliation for order :order found no discrepancy.',
        'duration' => 'Discrepancy period: from :from to :to.',
        'restored' => 'Restored: order reconciliation against the payment provider.',
    ],
    'family' => [
        'checkout' => [
            'subject' => [
                'opened' => '[:store] :severity: checkout check failing',
                'reopened' => '[:store] :severity: checkout check failing again',
                'recovered' => '[:store] Checkout check passing again',
            ],
            'recovery' => [
                'fact' => 'Two scheduled checks in a row reached the payment form.',
                'restored' => 'Restored: customer path to the payment form.',
            ],
            'components' => [
                'test_product' => [
                    'subject' => [
                        'opened' => '[:store] Checkout check is not running',
                        'reopened' => '[:store] Checkout check is not running',
                        'recovered' => '[:store] Checkout check is running again',
                    ],
                    'recovery' => [
                        'fact' => 'The check reached the payment form again.',
                        'restored' => 'Restored: the checkout check is running.',
                    ],
                ],
                'monitoring_access' => [
                    'subject' => [
                        'opened' => '[:store] Checkout check is not running',
                        'reopened' => '[:store] Checkout check is not running',
                        'recovered' => '[:store] Checkout check is running again',
                    ],
                    'recovery' => [
                        'fact' => 'The check reached the payment form again.',
                        'restored' => 'Restored: the checkout check is running.',
                    ],
                ],
                'adapter' => [
                    'subject' => [
                        'opened' => '[:store] Checkout check is not running',
                        'reopened' => '[:store] Checkout check is not running',
                        'recovered' => '[:store] Checkout check is running again',
                    ],
                    'recovery' => [
                        'fact' => 'The check reached the payment form again.',
                        'restored' => 'Restored: the checkout check is running.',
                    ],
                ],
            ],
        ],
        'integration' => [
            'subject' => [
                'opened' => '[:store] :severity: no data from the store plugin',
                'reopened' => '[:store] :severity: no data from the store plugin again',
                'recovered' => '[:store] Store plugin data is arriving again',
            ],
            'recovery' => [
                'fact' => 'The store plugin is back and delivering data. Checks for the period without data will be recalculated.',
                'restored' => 'Restored: connection to the store plugin.',
            ],
        ],
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
    'check_step' => [
        'product' => 'product page',
        'add_to_cart' => 'add to cart',
        'cart' => 'cart',
        'checkout' => 'checkout',
        'shipping' => 'address and shipping',
        'payment_form' => 'payment form',
        'unknown' => 'unknown step',
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
