# ADR 0088: Third-party reads allowed, trackers dropped, payment providers only with the setting

Date: 2026-10-29. Status: accepted (owner, 2026-10-29: «зачем мы вообще блокируем скрипты? Разве мы тем самым не создаем ложные поломки?»; payment forms stay behind the setting; trackers — the widest list possible; a provider token handed out by GET must not be obtained without the setting).

## Context

The browser check of a connected store loaded only the store origin and the scenario's extra origins; every other host was blocked. A store page that needs a third-party script (search, reviews, a payment plugin's script) then behaved differently for the robot than for a customer, which produced false failures or «blocked» results (the first real Shopware store: Novalnet, Doofinder, Trusted Shops). The reasons for blocking were: no purchase or charge, no robot visits in the store's analytics and ads, no load on payment providers, and egress control.

## Decision

The network policy (`apps/browser-worker/src/networkPolicy.js`, lists in `src/hosts.js`) decides by what a request does, not only by its origin:

1. **Reads of public third-party hosts are allowed** (GET/HEAD/OPTIONS, https, default port, a public host name — no IP literal, `localhost` or internal/reserved suffix; navigation of the main page to another origin stays blocked). Egress control is unchanged (squid denies private networks).
2. **Writes to third-party hosts are blocked** (`third_party_write`), and any third-party path that looks like a capture/confirm/refund stays `payment_capture`.
3. **Known trackers are dropped quietly** (`quiet`, not counted as blocked traffic): analytics and tag managers, session replay, advertising and retargeting, social pixels, affiliate networks, marketing automation and pop-up/e-mail capture, A/B testing and personalisation, web push, fingerprinting/attribution and visitor identification — 523 hosts and 34 host+path entries (e.g. only `facebook.com/tr`, not facebook.com). The store's own origin is never a tracker. The list is our own compilation (host names are facts; no third-party blocklist is copied, so no licence terms apply).
4. **Payment providers are reached only with «Check payment forms fully»**, and then only for reads; writes are `provider_write`. Without the setting every request to them — reads included, so no token handed out by GET — is blocked. A provider is a host of the list (291 hosts and 7 host+path entries: card schemes, wallets, PSPs, BNPL, open banking, 3-D Secure and fraud screening) or a host whose name carries a payment word (`pay`, `payment`, `checkout`, `wallet`, `gateway`, `psp`, `3ds`, …) as a separate label part — an unknown provider is treated as one.
5. **Tokens through the store:** WooCommerce PayPal Payments' own client-token and simulate-cart routes (`wc-ajax=ppc-*`) are allowed only with the setting. Other store-side routes of payment plugins cannot be recognised in general; a token obtained that way is unusable because the provider's script is blocked, and no write reaches a provider.
6. The public storefront check keeps its own rules (ADR 0058); trackers are dropped there too.

## Consequences

- Store pages work for the robot as for a customer, apart from trackers and payment forms; fewer false failures.
- A tracker missing from the list can still count a robot visit by a read; it is added when seen. Nothing can be bought: writes are blocked whatever the setting.
- Pages are heavier (third-party scripts load); the step budgets are unchanged.
