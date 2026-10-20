/*
 * Business Watchdog checkout observation (ADR 0062). Counts only the stages of the shop's own checkout — confirm
 * page shown, payment form ready, «Submit order» sent, finish page reached — so the merchant learns when customers can
 * no longer complete a purchase. Reports go only to the shop itself (the app's storefront endpoint); the browser never
 * contacts Business Watchdog. It reads no form values, stores nothing on the device (no cookies or storage), sets no
 * identifiers and never links pages of one visitor. Every part is guarded so it can never break the checkout.
 */
(function () {
    'use strict';

    // Do not extend Shopware's checkout templates or execute an app script in page rendering.
    // The app is observational: a failure here must leave the shop's own form untouched.
    var page = null;

    try {
        var pathname = (window.location.pathname || '').replace(/\/+$/, '');
        page = /\/checkout\/confirm$/.test(pathname) ? 'confirm' : (/\/checkout\/finish$/.test(pathname) ? 'finish' : null);
    } catch (e) { return; }

    if (page === null) {
        return;
    }

    var errors = 0;

    try {
        window.addEventListener('error', function () { errors++; }, true);
        window.addEventListener('unhandledrejection', function () { errors++; });
    } catch (e) { /* never break the page */ }

    function start() {
        try {
            window.BusinessWatchdogCheckout = { version: 3, page: page, state: 'available' };

            if (!navigator.sendBeacon || typeof URLSearchParams !== 'function') {
                return;
            }

            var endpoint = new URL('/storefront/script/bw-observe', window.location.href).toString();

            var send = function (stages) {
                try {
                    navigator.sendBeacon(endpoint, new URLSearchParams({ page: page, stages: stages.join(','), errors: String(errors) }));
                    errors = 0;
                } catch (e) { /* never break the page */ }
            };

            if (page === 'finish') {
                send(['rendered', 'script_loaded', 'finish_reached']);

                return;
            }

            var stages = ['rendered', 'script_loaded'];
            var form = document.getElementById('confirmOrderForm');
            var submit = document.getElementById('confirmFormSubmit');
            var methods = document.querySelectorAll('.payment-method-input').length;

            if (form && submit && !submit.disabled && methods > 0) {
                stages.push('form_ready');
            }

            if (/\/checkout\/confirm\/?$/.test((document.referrer || '').split('?')[0]) && document.querySelector('.alert-danger')) {
                stages.push('returned_with_error');
            }

            send(stages);

            if (form) {
                form.addEventListener('submit', function () {
                    try {
                        send(['submit_clicked']);
                        var left = false;
                        window.addEventListener('pagehide', function () { left = true; });
                        setTimeout(function () {
                            if (!left) {
                                send(['submit_stalled']);
                            }
                        }, 20000);
                    } catch (e) { /* never break the shop's submit */ }
                });
            }
        } catch (e) { /* never break the page */ }
    }

    try {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', start);
        } else {
            start();
        }
    } catch (e) { /* never break the shop */ }
})();
