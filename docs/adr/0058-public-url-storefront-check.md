# ADR 0058: Public storefront check by URL before registration

Status: accepted for implementation (owner request 2026-10-14; implemented in Step 145 with the restrictive defaults below).

## Implementation note

Step 145 implements the first slice: public site form/result page, mandatory local image math CAPTCHA with noise, normalized host/origin and IP cooldowns, submit throttling, public HTTPS and SSRF checks, separate public diagnostic records/artifacts, browser-worker `public-storefront@1`, screenshots of public steps, and 5-minute cleanup. After owner review, the first slice may perform one narrowly allowlisted same-origin public add-to-cart action to reach cart/checkout. Checkout/order/payment/account/login/register mutations remain blocked. The worker records only a cautious public `detected_platform` hint (`woocommerce_hint`, `shopware_hint`, `unknown`), not a verified platform claim.

## Context

The public site should let a visitor enter a store URL and get a quick visual diagnostic without registering, proving ownership, installing the WooCommerce plugin, installing the Shopware App, or providing API keys.

This is useful for onboarding and sales: a merchant can see whether Business Watchdog can reach their public storefront and where the public checkout path stops. It must not be presented as monitoring, independent financial reconciliation, or proof that real payments work.

Because the feature runs our browser infrastructure against a user-supplied address, the default posture is restrictive. Without ownership proof the service also has no consent to inspect private pages, account areas, order pages, payment redirects, real customer data, or non-public diagnostics.

## Decisions

1. **Surface.** Add a public site page (for example `/check-store` under each locale) with a single HTTPS URL input, required CAPTCHA, clear limitations, and a result page. No account, tenant, store, integration, incident, finding, billing entitlement or connector record is created.
2. **Scope.** The check is a one-off public storefront smoke test:
   - validate DNS/TLS/redirects and public HTTPS reachability;
   - load the given public page or normalized origin;
   - if safe and recognizable, attempt a public storefront path: storefront/catalog/product -> cart -> checkout;
   - classify stop reasons such as login required, no product found, checkout unavailable, WAF/CAPTCHA/geoblock, platform unsupported, timeout, 5xx, critical console error, critical network failure;
   - capture screenshots of the relevant public steps and the failure point.
3. **Strict non-goals.** The result must not claim:
   - ownership verification;
   - payment success or payment availability;
   - server-side payment attempt observation;
   - financial/provider coverage;
   - reconciliation health;
   - ongoing monitoring;
   - incident/finding creation.
4. **Mutations.** The browser may only perform narrowly reviewed storefront/cart actions needed to reach the public checkout path. It must never submit an order, create/capture/refund a payment, sign in, register an account, enter a real email/password, follow hosted payment confirmation, access account/order/admin pages, or execute arbitrary JavaScript/selectors supplied by the visitor.
5. **Abuse controls.**
   - CAPTCHA is mandatory before a check is queued or run.
   - Only public HTTPS URLs are accepted.
   - Localhost, private/reserved IP ranges, link-local, metadata services, onion/internal names and DNS rebinding are rejected with the same SSRF-safe resolver/egress principles as browser checks.
   - The same normalized host/origin can be checked at most once every 30 days.
   - One IP can successfully check only one site every 10 days.
   - A separate short submit-attempt limit applies per IP so rejected/private/duplicate attempts cannot be spammed.
   - IPs used for anti-abuse should be stored as keyed hashes with TTL where practical; raw IP storage requires an explicit privacy reason.
   - Browser runtime, page count, redirects, screenshot count and artifact size are tightly bounded.
6. **Artifacts and retention.**
   - Screenshots and diagnostics live in a separate public-diagnostic artifact category, not tenant artifacts.
   - Result links are unlisted, noindex and short-lived.
   - Screenshots and diagnostics are deleted after 5 minutes by a frequent cleanup job.
   - If a redirect or page appears to be an admin/account/order/private area or contains obvious credential/PII forms, the worker stops and either skips the screenshot or masks the page before showing it.
7. **Verdict wording.** Use cautious states:
   - public path reached checkout;
   - deeper check needs connection/verification;
   - public issue found;
   - blocked by WAF/CAPTCHA/login/geography;
   - insufficient data / platform not recognized.
   A successful result only means the public smoke path worked at that time.
8. **Operations.** Public URL checks use a separate queue/channel and capacity limit from paid tenant browser checks so free public diagnostics cannot starve customer monitoring.

## Open questions before implementation

- Whether a stronger external CAPTCHA provider is needed later if the local math CAPTCHA is abused.
- Exact public page route and result URL lifetime.
- Whether to persist a minimal anonymized aggregate count after the 5-minute artifact deletion for abuse monitoring and product analytics.
- Which platform heuristics are included in the first slice: generic HTML only, WooCommerce hints, Shopware hints, or both.

## Verification plan

- Unit tests for URL normalization, host/origin cooldown, IP cooldown, submit rate limit, CAPTCHA-required path and SSRF/private-destination rejection.
- Worker/policy tests that order/payment/account/admin mutations are blocked and that arbitrary user JS/selectors are impossible.
- Feature tests for artifact deletion after 5 minutes and non-tenant artifact authorization.
- Public site tests for locale text, noindex result, cautious verdict wording and no claim of payment/financial coverage.
- Abuse tests for repeated same host, repeated IP, rejected submissions and queue capacity limits.
