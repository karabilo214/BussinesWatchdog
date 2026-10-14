/*
 * Business Watchdog checkout observation (ADR 0059). Counts only the stages of the shop's own checkout — page shown,
 * payment form ready, order sent, error shown, stalled, order-received page reached — so the merchant learns when
 * customers can no longer complete a purchase. It reads no form values, stores nothing on the device (no cookies or
 * storage), sets no identifiers and never links pages of one visitor; reports go to this shop's own WordPress and only
 * counters per 5 minutes leave it. Every part is guarded so it can never break the checkout.
 */
(function () {
    'use strict';

    var errors = 0;

    try {
        window.addEventListener('error', function () { errors++; }, true);
        window.addEventListener('unhandledrejection', function () { errors++; });
    } catch (e) { /* never break the page */ }

    var config = window.BW_CHECKOUT_OBSERVER || null;
    var current = document.currentScript;
    var altered = !current || current.defer || current.async || !/checkout-observer\.js/.test(current.src || '');

    try {
        window.BusinessWatchdogCheckout = { version: 1, page: config ? config.page : null, state: !config ? 'off' : (config.synthetic ? 'synthetic' : 'observing') };
    } catch (e) { /* never break the page */ }

    if (!config || config.synthetic || !navigator.sendBeacon || typeof JSON === 'undefined') {
        return;
    }

    function send(stages) {
        try {
            navigator.sendBeacon(config.endpoint, JSON.stringify({ token: config.token, page: config.page, stages: stages, js_errors: errors }));
            errors = 0;
        } catch (e) { /* never break the page */ }
    }

    function present(selector) {
        try {
            return document.querySelector(selector) !== null;
        } catch (e) {
            return false;
        }
    }

    function formReady() {
        var classic = document.querySelector('form.checkout #place_order');
        var blocks = document.querySelector('.wc-block-components-checkout-place-order-button');
        var button = classic || blocks;

        if (!button || button.disabled || button.getAttribute('aria-disabled') === 'true') {
            return false;
        }

        return present('#payment .wc_payment_method, #payment input[name="payment_method"], .wc-block-checkout__payment-method .wc-block-components-radio-control__option, .wc-block-checkout__payment-method input[type="radio"], .wc-block-checkout__payment-method .wc-block-components-payment-method-label');
    }

    function errorShown() {
        return present('.woocommerce-NoticeGroup-checkout .woocommerce-error, form.checkout .woocommerce-error, .wc-block-components-notice-banner.is-error, .wc-block-store-notice.is-error');
    }

    function watchSubmit() {
        var left = false;
        var reported = false;

        window.addEventListener('pagehide', function () { left = true; });

        var check = window.setInterval(function () {
            if (!reported && errorShown()) {
                reported = true;
                send(['returned_with_error']);
            }
        }, 500);

        window.setTimeout(function () {
            window.clearInterval(check);

            if (!left && !reported && !errorShown()) {
                send(['submit_stalled']);
            }
        }, 60000);
    }

    function start() {
        try {
            var first = ['script_loaded'];

            if (altered) {
                first.push('script_altered');
            }

            if (config.page === 'finish') {
                first.push('finish_reached');
                send(first);

                return;
            }

            send(first);

            var tries = 0;
            var ready = window.setInterval(function () {
                tries++;

                if (formReady()) {
                    window.clearInterval(ready);
                    send(['form_ready']);
                } else if (tries > 40) {
                    window.clearInterval(ready);
                }
            }, 500);

            document.addEventListener('submit', function (event) {
                if (event.target && event.target.matches && event.target.matches('form.checkout')) {
                    send(['submit_clicked']);
                    watchSubmit();
                }
            }, true);

            document.addEventListener('click', function (event) {
                var button = event.target && event.target.closest ? event.target.closest('.wc-block-components-checkout-place-order-button') : null;

                if (!button) {
                    return;
                }

                window.setTimeout(function () {
                    if (!present('.wc-block-components-validation-error, .has-error .wc-block-components-validation-error')) {
                        send(['submit_clicked']);
                        watchSubmit();
                    }
                }, 1500);
            }, true);
        } catch (e) { /* never break the page */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
