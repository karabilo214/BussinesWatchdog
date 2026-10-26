=== Business Watchdog for WooCommerce ===
Contributors: karabin214
Tags: woocommerce, payments, monitoring, checkout, reconciliation
Requires at least: 5.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.3.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Finds orders and payments that do not match and tells you when customers can no longer pay. Connects your store to Business Watchdog.

== Description ==

Business Watchdog watches the money side of a WooCommerce store. It compares the orders of your store with what your payment providers (Stripe, PayPal) really received and refunded, and warns you when they disagree: an order marked as paid without a charge, a charge without a paid order, a refund missing on one side, payments that keep failing.

This plugin is the connector for the Business Watchdog service. **You need a Business Watchdog account** (a subscription with a free trial) at [business-watchdog.com](https://business-watchdog.com). The plugin does nothing until you connect it to your account with a one-time code.

You can also connect a store **without installing anything**: Business Watchdog reads orders through the WooCommerce REST API with a read-only key, and WooCommerce's own webhooks make it instant. The plugin adds what a key cannot:

* Order and refund changes are sent the moment they happen, also on WooCommerce versions where the REST API notices refunds late.
* Payment attempts at checkout are counted per payment method, so repeated failures of one method are noticed early.
* Optional checkout observation: on the checkout and order-received pages a small script counts whether the payment form was shown and whether placing the order worked. It reports only to your own site; your customers' browsers never contact Business Watchdog.
* The service's own browser checks of your checkout are recognised and never counted as customers.

The plugin never takes part in payment, order creation, stock or store e-mails, and nothing calls Business Watchdog while a customer is on your site. If the service is unreachable, your store keeps working; changes wait in a local queue.

= Emergency stop =

* WooCommerce → Business Watchdog → **Stop monitoring**, or deactivate the plugin.
* WP-CLI: `wp business-watchdog stop`.
* `define( 'BUSINESS_WATCHDOG_DISABLED', true );` in `wp-config.php` stops the plugin before any of its code runs.

== External services ==

This plugin connects your store to the **Business Watchdog** service operated at https://business-watchdog.com. Nothing is sent before you connect the plugin with a pairing code from your Business Watchdog account; disconnecting or deactivating the plugin stops all sending.

What is sent, when connected:

* **When you connect:** the one-time pairing code, your site address, a random installation ID and the plugin version.
* **Order and refund changes, as they happen and in a daily check:** order ID and number, status, currency, total, payment method ID and display name, the payment provider's transaction reference, the date the order was marked paid; for refunds the amount, the provider's refund reference and the order they belong to. No customer names, addresses, e-mail addresses, phone numbers or order items are sent.
* **Every 5 minutes (heartbeat):** plugin version and the state of the local queue.
* **When versions change:** the versions of WordPress, WooCommerce, the active theme and active plugins, and whether HPOS and the block checkout are used — to tell you when an update coincides with a problem.
* **Every few minutes:** counts of payment attempts per payment method and outcome, and — only if checkout observation is enabled for your store — counts of checkout stages per 5-minute window. No IP addresses, browser details or visitor identifiers.

The service processes this data on your behalf as a processor under a data processing agreement (Art. 28 GDPR) on servers in Germany.

* Terms of Service: https://business-watchdog.com/en/terms
* Privacy Policy: https://business-watchdog.com/en/privacy

== Installation ==

1. Install and activate the plugin (WooCommerce 6.0 or newer must be active).
2. In your Business Watchdog account open your store, choose «WooCommerce plugin» and create a connection code.
3. In WordPress open WooCommerce → Business Watchdog, paste the code and click **Connect**.

== Frequently Asked Questions ==

= Do I need the plugin? =

No. You can connect with a read-only WooCommerce REST API key instead and add WooCommerce webhooks for instant updates. The plugin adds immediate refund changes on older WooCommerce versions, payment attempts per payment method and the checkout observation.

= Does the plugin slow down checkout? =

No request to Business Watchdog is made while a customer is on your site. Changes are collected at the end of the request and sent in the background.

= Do my customers need to consent to the checkout observation? =

The observation script stores nothing on the device, sets no cookies or identifiers, reads no form fields and reports only to your own site. Business Watchdog provides a sample paragraph for your privacy policy in your account. This is not legal advice.

= What happens to my data when I uninstall? =

Uninstalling removes the plugin's options, schedules and connection. Its own tables are removed only if you choose so on the settings page.

== Screenshots ==

1. An incident: the store marked an order as paid, but the payment provider has no capture for it — with the amounts and what to check.
2. Open incidents across your stores.
3. What the service sees for a store: the order source, money reconciliation, payment attempts, checkout observation and checks.
4. The plugin in WooCommerce: connection status and the emergency stop.

== Changelog ==

= 0.3.4 =
* Sends the store's payment method name with order changes so custom methods have a readable label in Business Watchdog.

= 0.3.3 =
* Prepared for the WordPress.org plugin directory: readme with the external service description, database queries name the plugin's tables directly, request input is unslashed and sanitized, translator comments.

= 0.3.2 =
* Isolates errors of the plugin's own hooks, does not update its database schema during customer requests and does not write the observation counter while the checkout renders.

= 0.3.1 =
* Handles a store paused by its plan and catches up on order changes after the pause.

= 0.3.0 =
* Optional checkout observation.

== Upgrade Notice ==

= 0.3.4 =
Custom payment methods can show their store name in Business Watchdog after the next order sync.

= 0.3.3 =
No change in behaviour; code cleanups for the WordPress.org plugin directory.

= 0.3.2 =
Safer handling of the plugin's own errors during customer requests.
