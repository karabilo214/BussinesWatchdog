# Shopware purchase lab

The local images `bw-shopware:6.6.10.29` and `bw-shopware:6.7.15.1` contain Shopware demo products. The script starts each one, runs the production scheduled adapter in all three checkout-access modes without submitting an order, then runs a separate buyer robot. It requires Docker and uses only `http://localhost` inside the Shopware container.

```sh
cd plugins/shopware-app/tests/purchase
./purchase-faults-e2e.sh bw-shopware:6.6.10.29 bw-shopware:6.7.15.1
```

The buyer robot makes one successful guest purchase with the built-in payment method. It then detects a disabled product button, a disabled cart checkout link, a cart script blocking navigation, a changed item price, an unavailable order button, and a lost response after Shopware created the order. The last case creates a second order, so an apparent timeout must never be retried blindly. Each version must end with exactly two orders; the scheduled adapter must create none. Run a subset with `BW_PURCHASE_MODES='cart_price cannot_buy'`.

The scheduled adapter now confirms the selected product and a positive cart line and total in every mode. `test_customer` and `guest` additionally confirm available delivery and payment choices. `stop_before_sign_in` stops before either choice becomes visible. A positive amount is checked here; the separate fault robot checks a cart price changed from the product page.

The lost response is between Shopware and the buyer's browser. It is **not** a PayPal timeout. `../paypal/run.sh dockware/play:6.7.2.2` exercises real payments through official SwagPayPal in PayPal Sandbox and checks the backend's independent provider reconciliation. It uses only sandbox credentials. The app's signed webhook and checkout observation paths have their own E2E suites under `../app` and `../observation`; this purchase lab itself does not install the app.

`../paypal/provider-timeout-e2e.sh` uses a disposable Shopware 6.7 container with valid PayPal Sandbox credentials, creates a guest order, disconnects the container's external network, and calls the actual payment handler over loopback. Shopware returns its payment error destination and marks the transaction `failed`. This proves the failure response when the provider is unreachable; it does not measure a real PayPal service outage. `../paypal/run.sh` also creates both financial divergences after three genuine Sandbox captures: a PayPal capture with a Shopware order left open, and a Shopware order marked paid without a PayPal capture.
