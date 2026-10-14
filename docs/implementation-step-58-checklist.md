# Implementation Step 58 Checklist: Public Storefront Check by URL

Stage: P1 onboarding/public-site diagnostic (ADR 0058; spec §18-21 safety principles; no financial/payment coverage)

## Scope

- [x] Public site page in ru/en/de where a visitor enters a public HTTPS store URL and completes a local noisy image math CAPTCHA before any check can run.
- [x] URL normalization to host/origin for cooldowns; public HTTPS only; SSRF-safe resolution and redirect checks reject localhost, private/reserved IP ranges, metadata services and internal names.
- [x] Anti-abuse gates:
  - [x] Same normalized host/origin: at most one successful check per 30 days.
  - [x] Same IP: at most one successful checked site per 10 days.
  - [x] Separate short submit-attempt limit per IP, including rejected attempts.
  - [x] Store IP data as TTL-bound keyed hashes where practical.
- [x] Separate public-diagnostic queue/capacity so public checks cannot starve tenant browser checks.
- [x] One-off browser diagnostic limited to public pages:
  - [x] DNS/TLS/redirect/reachability.
  - [x] Public storefront/catalog/product/cart/checkout path when safely recognizable.
  - [x] Cautious public platform hint (WooCommerce / Shopware / unknown), never a verified platform claim.
  - [x] Stop reasons for login, WAF/CAPTCHA, geoblock, unsupported platform, no product, checkout unavailable, timeout, 5xx, critical console/network errors.
- [x] Network/browser policy allows at most narrowly reviewed same-origin public add-to-cart actions and blocks order submit, payment/capture/refund, sign-in, registration, account/order/admin pages, hosted payment confirmation and arbitrary visitor-supplied JavaScript/selectors.
- [x] Public diagnostic artifacts:
  - [x] Screenshot key steps and failure point only when the page is public and safe.
  - [x] Separate artifact category from tenant check artifacts.
  - [x] Result URLs are unlisted, noindex and short-lived.
  - [x] Screenshots/diagnostics deleted after 5 minutes by a frequent cleanup job.
- [x] Result wording is cautious: public path reached checkout, deeper check needs connection, public issue found, blocked, or insufficient data. No monitoring/financial/payment/reconciliation claim.
- [x] Public backlog item is closed with Step 145 once implementation and automated tests are complete.

## Verification

- [x] URL normalization, cooldown, rate limit, CAPTCHA-required and SSRF rejection tests.
- [x] Worker/network policy tests for blocked mutations and absence of arbitrary user JS/selectors.
- [x] Artifact authorization and 5-minute purge tests.
- [x] Public page locale/noindex/verdict wording tests.
- [x] Queue capacity/abuse tests showing public checks cannot starve tenant monitoring.
- [ ] Manual browser smoke with a harmless demo shop and a WAF/login-blocked target, using only public pages.
