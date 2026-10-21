# ADR 0075: Checkout pages and elements per store for third-party Shopware themes

Date: 2026-10-21

Status: accepted (owner's backlog task 2026-10-21, decisions of ADR 0074; technical choices below made in the session after the lawyer's answer in ADR 0062)

## Context

The Shopware app's observation script (ADR 0057/0062) and the browser check's Shopware adapter both assume the Storefront default theme: pages `/checkout/confirm` and `/checkout/finish`, the form `#confirmOrderForm`, the button `#confirmFormSubmit`, payment radios `.payment-method-input`, errors `.alert-danger`. A third-party theme or a custom checkout can rename or move them; then the script reports nothing useful and the check ends `selector_changed`. The customer can change only the check's `checkout_url`, which the app's script never sees. Since 1.3.1 the app has no Twig templates, so the script cannot be configured through the page.

## Decisions

1. **One setting per store, «checkout pages and elements»** (`stores.settings.checkout_layout`), used by both the browser check and the app's script. Fields: confirm page path, finish page path, and CSS selectors for the order form, the submit button, the payment method inputs and the error notice. Every field is optional; an empty field means the Shopware default. The check's own `checkout_url` stays; when it is not set, the check opens the store origin plus the confirm path of this setting.
2. **No JavaScript, only data.** Paths: relative, start with `/`, at most 200 characters, no `..`, `//`, query or fragment; a page matches when the browser path ends with it (sales-channel prefixes like `/de` keep working). Selectors: at most 200 characters, a restricted character set (letters, digits, space and `#.-_[]=:"'>~+*,()^$|`); both the script and the worker run them inside `try/catch`, so a selector that does not parse means «not found», never an error on the shop's page.
3. **The script gets the setting from its own shop.** The service writes the layout together with the observe flag into the app's setting entity (the existing write boundary: only the app's own entities). The script asks the app's storefront endpoint `GET /storefront/script/bw-observe?config=1` once and the browser may cache the answer for 5 minutes; the answer carries nothing about the visitor. The lawyer's answer (ADR 0062) covers requests of the script to the shop itself. When the flag says «not observed», the script sends no reports at all.
4. **The script only observes**: it never clicks, fills or changes the form. Its marker (`window.BusinessWatchdogCheckout`) also tells which elements it found on the page (form, submit button, number of payment methods), so the browser check can confirm the script and the layout in one visit.
5. **Checking the setting = the browser check on the live store** (no copy, ADR 0074). The cabinet shows, per element, whether the last check found it and whether the app's script loaded and saw it. A path, selector or script that stopped working after a theme change makes the observation «unavailable / not confirmed» — never «checkout broken»; the check's own outcome for the purchase path is unchanged.
6. **Order of work:** (A) the setting, its validation, the API and the cabinet form, the check's default URL; (B) app 1.4.0 — setting entity, config endpoint, script with the layout and the marker; (C) the worker's Shopware adapter with the layout and element results; (D) coverage «observation not confirmed» and the cabinet view of found elements.

## Consequences

- A store on the default theme needs no setting; nothing changes for it.
- The app version changes (new entity field, endpoint answer, script), so 1.4.0 needs a new approval of its fingerprint (ADR 0074).
- A selector that matches a different element than intended can make the check pass a wrong element; the cabinet shows what was found, the customer stays responsible for the setting.
