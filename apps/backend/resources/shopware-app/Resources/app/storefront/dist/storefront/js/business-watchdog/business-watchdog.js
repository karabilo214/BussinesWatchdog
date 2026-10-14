/*
 * Business Watchdog checkout observation (ADR 0057). Counts only the stages of the shop's own checkout — confirm
 * page shown, payment form ready, «Submit order» sent, finish page reached — so the merchant learns when customers can
 * no longer complete a purchase. It reads no form values, stores nothing on the device (no cookies or storage), sets
 * no identifiers and never links pages of one visitor; the service keeps only counters per 5 minutes. Every part is
 * guarded so it can never break the checkout.
 */
(function () {
    'use strict';

    var errors = 0;

    try {
        window.addEventListener('error', function () { errors++; }, true);
        window.addEventListener('unhandledrejection', function () { errors++; });
    } catch (e) { /* never break the page */ }

    function start() {
        try {
            var root = document.getElementById('bw-checkout-observer');
            window.BusinessWatchdogCheckout = { version: 1, page: root ? root.getAttribute('data-page') : null, state: 'loaded' };

            if (!root || typeof fetch !== 'function' || !navigator.sendBeacon) {
                return;
            }

            var endpoint = root.getAttribute('data-endpoint');
            var page = root.getAttribute('data-page');

            var send = function (stages) {
                try {
                    navigator.sendBeacon(endpoint + '/stages', JSON.stringify({ page: page, stages: stages, js_errors: errors }));
                    errors = 0;
                } catch (e) { /* never break the page */ }
            };

            fetch(endpoint + '/config', { credentials: 'omit', cache: 'no-store' })
                .then(function (response) { return response.ok ? response.json() : { observe: false }; })
                .then(function (config) {
                    if (!config || config.observe !== true) {
                        window.BusinessWatchdogCheckout.state = 'off';

                        return;
                    }

                    window.BusinessWatchdogCheckout.state = 'observing';

                    if (page === 'finish') {
                        send(['script_loaded', 'finish_reached']);

                        return;
                    }

                    var stages = ['script_loaded'];
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
                            send(['submit_clicked']);
                            var left = false;
                            window.addEventListener('pagehide', function () { left = true; });
                            setTimeout(function () {
                                if (!left) {
                                    send(['submit_stalled']);
                                }
                            }, 20000);
                        });
                    }
                })
                .catch(function () { /* the service is unreachable or blocked: the render beacon still counts the page */ });
        } catch (e) { /* never break the page */ }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
