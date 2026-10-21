/*
 * Business Watchdog checkout observation (ADR 0062, 0075). Counts only the stages of the shop's own checkout — confirm
 * page shown, payment form ready, «Submit order» sent, finish page reached — so the merchant learns when customers can
 * no longer complete a purchase. The pages and elements come from the store's setting, read from the shop itself; the
 * browser never contacts Business Watchdog. It reads no form values, stores nothing on the device (no cookies or
 * storage), sets no identifiers and never links pages of one visitor. Every part is guarded so it can never break the
 * checkout.
 */
(function () {
    'use strict';

    var DEFAULTS = {
        confirm_path: '/checkout/confirm',
        finish_path: '/checkout/finish',
        order_form: '#confirmOrderForm',
        submit_button: '#confirmFormSubmit',
        payment_method: '.payment-method-input',
        error_notice: '.alert-danger'
    };
    var errors = 0;

    try {
        window.addEventListener('error', function () { errors++; }, true);
        window.addEventListener('unhandledrejection', function () { errors++; });
    } catch (e) { /* never break the page */ }

    function endpoint(query) {
        return new URL('/storefront/script/bw-observe' + query, window.location.href).toString();
    }

    function clean(path) {
        return String(path || '').replace(/\/+$/, '');
    }

    function pageOf(layout) {
        var pathname = clean(window.location.pathname);
        var confirm = clean(layout.confirm_path);
        var finish = clean(layout.finish_path);

        if (confirm !== '' && pathname.slice(-confirm.length) === confirm) {
            return 'confirm';
        }

        return finish !== '' && pathname.slice(-finish.length) === finish ? 'finish' : null;
    }

    function find(selector, all) {
        try {
            return all ? document.querySelectorAll(selector).length : document.querySelector(selector);
        } catch (e) {
            return all ? 0 : null;
        }
    }

    function start(config) {
        try {
            var custom = config && typeof config.layout === 'string' ? JSON.parse(config.layout) : null;
            var layout = {};

            for (var key in DEFAULTS) {
                layout[key] = custom && typeof custom[key] === 'string' && custom[key] !== '' ? custom[key] : DEFAULTS[key];
            }

            var page = pageOf(layout);

            if (page === null) {
                return;
            }

            var form = page === 'confirm' ? find(layout.order_form, false) : null;
            var submit = page === 'confirm' ? find(layout.submit_button, false) : null;
            var methods = page === 'confirm' ? find(layout.payment_method, true) : 0;

            window.BusinessWatchdogCheckout = {
                version: 4,
                page: page,
                state: 'available',
                layout: custom ? 'custom' : 'default',
                config: config ? 'loaded' : 'unavailable',
                found: { form: form !== null, submit: submit !== null, payment_methods: methods }
            };

            if ((config && config.observe === false) || !navigator.sendBeacon || typeof URLSearchParams !== 'function') {
                return;
            }

            var send = function (stages) {
                try {
                    navigator.sendBeacon(endpoint(''), new URLSearchParams({ page: page, stages: stages.join(','), errors: String(errors) }));
                    errors = 0;
                } catch (e) { /* never break the page */ }
            };

            if (page === 'finish') {
                send(['rendered', 'script_loaded', 'finish_reached']);

                return;
            }

            var stages = ['rendered', 'script_loaded'];

            if (form && submit && !submit.disabled && methods > 0) {
                stages.push('form_ready');
            }

            if (clean((document.referrer || '').split('?')[0]).slice(-clean(layout.confirm_path).length) === clean(layout.confirm_path) && find(layout.error_notice, false)) {
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

    function load() {
        try {
            if (typeof fetch !== 'function') {
                start(null);

                return;
            }

            fetch(endpoint('?config=1'), { credentials: 'omit', headers: { Accept: 'application/json' } })
                .then(function (response) { return response.ok ? response.json() : null; })
                .then(function (config) { start(config && typeof config === 'object' ? config : null); }, function () { start(null); });
        } catch (e) {
            start(null);
        }
    }

    try {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', load);
        } else {
            load();
        }
    } catch (e) { /* never break the shop */ }
})();
