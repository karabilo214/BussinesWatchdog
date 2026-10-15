# Local purchase lab

Run from this directory. The lab resets the named matrix target unless `BW_MATRIX_REUSE=1` is set. Use it only with the local Docker WooCommerce target. The purchase robot refuses any origin other than `http://wordpress`.

```sh
./purchase-faults-e2e.sh latest
```

The robot creates one BACS order and checks six injected faults: product button disabled, cart checkout button disabled, cart JavaScript blocking checkout, cart price different from the product price, no payment methods, and a gateway returning HTTP 504. The injector is a matrix-only MU plugin; it needs both WordPress `local` environment and the robot's explicit request header. `BW_PURCHASE_MODES='cart_price gateway_no_response' BW_MATRIX_REUSE=1 ./purchase-faults-e2e.sh latest` runs a subset.

Before the purchase robot, the script runs the production scheduled adapter against Classic and, when available, Blocks checkout. It confirms the selected product, selected variation and positive cart amount, then checks a selectable payment method without creating an order. This lab's product is virtual, so the shipping step is skipped; physical delivery is covered by separate browser adapter tests and the Shopware lab.

The separate provider scripts use credentials from `apps/backend/.env`. They require Stripe **test** keys or PayPal **Sandbox** credentials and create provider sandbox transactions:

```sh
./stripe-gateway-spike.sh latest
./paypal-gateway-spike.sh latest
./stripe-divergence-e2e.sh latest
```

Run these one at a time: each can reset the `latest` matrix container. `stripe-divergence-e2e.sh` asserts both provider paid/store unpaid and store paid/provider declined. It revokes the temporary backend Stripe credential on exit but retains test history. The local WordPress image has no `sendmail`, so checkout email delivery is outside this lab. The gateway 504 is a simulated failure; it does not establish how a real provider timeout behaves. The production scheduled browser worker has a separate no-order policy and never uses this purchase robot.
