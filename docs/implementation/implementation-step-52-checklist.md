# Implementation Step 52 Checklist: Browser Worker

Stage: P0 (spec §18–21; OpenAPI `/internal/v1/browser/*`; ADR 0011)

## Scope

- [x] `apps/browser-worker`: Node ESM, `playwright` 1.55.0, Docker image on `mcr.microsoft.com/playwright:v1.55.0-noble`, non-root, sandbox on, read-only root; Compose service (profile `browser`); `make worker-test`, `make worker-e2e`.
- [x] Lease loop with jittered idle backoff, 15 s heartbeat, absolute deadline, result retry until the lease ends, graceful shutdown; lease token never logged.
- [x] WooCommerce adapter (Classic + Blocks detected at runtime) with step outcome classification.
- [x] Network policy for every request and WebSocket: allowed HTTPS origins, cart-only mutations (urlencoded and multipart), blocked order placement / order-pay / capture / refund, unknown mutations blocked; own blocking never reported as a store failure.
- [x] Sanitized diagnostics: redacted paths, status codes, error classes, bounded and aggregated.
- [x] Matrix e2e `browser-e2e.sh`: real backend + worker container against every WooCommerce target; Classic, Blocks (where the version creates a block checkout page), no-payment-methods negative case; order and payment-attempt counts unchanged.

## Verification

- [x] Worker unit tests 10/10 (policy, multipart, redaction, error log).
- [x] Browser e2e PASS on floor, wc7-legacy, wc7-hpos, wc8, wc9, latest (`docs/compatibility.md`).
- [x] Backend suites unchanged and green.

## Not Done In This Step

- Network-level egress proxy (required before production); screenshots/artifacts, synthetic marker and Blocks draft cleanup (Step 53); variable products, shipping-gated payment steps, third-party gateway iframes.
