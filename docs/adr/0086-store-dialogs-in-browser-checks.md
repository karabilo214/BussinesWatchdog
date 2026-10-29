# ADR 0086: Store dialogs and preloaders in browser checks

Date: 2026-10-29. Status: accepted (owner, 2026-10-29: «должен знать и про куки и про такие блок-диалоги», «на некоторых сайтах сначала грузится заглушка»).

## Context

The first real Shopware store (a tobacco shop) ended every browser check as `inconclusive / infra_timeout` — shown to the merchant as «a failure on our side». The product page carried a NetzP age check (`#netzpCheckAgeOverlay`, «Sind Sie 18 Jahre alt oder älter? JA / NEIN») over the whole page and the classic Shopware cookie bar. The click on «In den Warenkorb» waited 20 s for the covered button; Playwright's timeout was not a `StepOutcome`, so the worker reported `worker_exception`. Three attempts, ~4 minutes, no information. The payment-form adapters only knew the Shopware 6.5+ cookie modal; the public storefront check has its own accept-based heuristic.

## Decision

1. **Dialogs are handled before every action** of the payment-form check (`page.addLocatorHandler`, `apps/browser-worker/src/adapter/interruptions.js`): known cookie banners (Shopware modal and classic bar, Cookiebot, OneTrust, Usercentrics, Borlabs, Complianz, CookieYes, Cookie Notice, CookieLawInfo, consentmanager, Klaro, CookieFirst), known age gates (NetzP, WordPress Age Gate), and two generic fallbacks.
2. **Cookies: the robot declines optional cookies.** Order: «only necessary / ablehnen / reject» → a neutral «OK / Verstanden». It never presses «accept all», never configures, subscribes or signs in. The check does not need marketing cookies, and declining keeps the store's analytics free of robot visits.
3. **Age gates: the robot confirms** («JA», «Ja, ich bin über 18», «Yes», «Enter»), never answers «no». A synthetic check with no purchase is not a person buying age-restricted goods; the store requires the confirmation from every visitor. Generic age dialogs are recognised only inside dialog-like containers or age-check class names with age text (18/21 + Jahre/years, Altersprüfung…).
4. **Preloaders:** after each navigation the robot waits up to 10 s (within the step budget) while a fixed/absolute element covering ≥ 80 % of the viewport with a preloader-like id/class (`preload`, `loader`, `loading`, `spinner`, `splash`) sits on the page.
5. **A dialog the robot cannot close is named, not hidden.** A click whose Playwright log says another element «intercepts pointer events» becomes `blocked / blocking_overlay` (new error code): final immediately, no retries, an info incident `CHECKOUT_MONITORING_BLOCKED` like bot protection. It is not a store failure and not a worker failure; the merchant is asked to tell us about the dialog.

## Consequences

- New error code `blocking_overlay` in `CheckOutcomePolicy::ERROR_CODES` (and TZ §18 list), texts in ru/en/de of both panels and notifications.
- New dialog types are added to the list in `interruptions.js` with a test in `test/interruptions.test.js`.
- The public storefront check keeps its own heuristic (ADR 0058) unchanged.
