# ADR 0010: Browser Check Backend (Scheduler, Lease Protocol, Outcomes)

Date: 2026-10-09

Status: backend implemented in Step 51; the Node/Playwright worker follows in Step 52. Decisions marked "owner" need confirmation.

## Decisions

- **Tables** follow `spec/database/schema.sql` (`check_scenarios`, `check_runs`, `check_attempts`, `check_steps`) plus `browser_workers` (name, SHA-256 of the bearer token, status). Artifacts (screenshots) come with Step 53. One `payment_form` scenario per store.
- **Eligibility.** A run is created or leased only for a store that is `active`, verified, has `browser_enabled`, an enabled scenario and an active tenant; a queued run whose store became ineligible is cancelled at lease time.
- **Scheduling.** `browser:schedule` every minute: default interval 15 min (300 s – 24 h), jitter ±10 %, one active run per store and per scenario (partial unique indexes), also recovers expired leases. Manual runs: owner/admin, `Idempotency-Key`, at most one per minute per store, `409` while a run is active.
- **Lease protocol** (spec §20, OpenAPI): bearer worker token; `FOR UPDATE SKIP LOCKED` on PostgreSQL; random 256-bit lease token returned once, only its hash stored; fencing token = run counter + 1; lease 150 s, heartbeat extends it but never past the 120 s absolute deadline + 30 s for submitting the result; results are accepted only from the current fenced attempt, identical replays return 200, different ones 409; expired attempts become `expired` without counting as a site failure.
- **Outcome policy.** Only `site_failure` can confirm a broken checkout: the first one is retried after 60 s, the second within the same logical run confirms it. Worker and infrastructure problems (`infra_timeout`, `worker_capacity`, `dns_failure`, expired leases) are retried up to 3 attempts and then end `inconclusive`, never as a failure. `blocked`, `unsupported`, `cancelled`, `passed` and `product_unavailable` are final immediately.
- **Incidents** (family `checkout`, one fingerprint per scenario and component):
  - `payment_form` / `CHECKOUT_FLOW_FAILED`, severity `warning` (critical needs a corroborating signal, spec §22 — not implemented);
  - coverage problems with severity `info`: `test_product` / `CHECKOUT_TEST_PRODUCT_UNAVAILABLE`, `monitoring_access` / `CHECKOUT_MONITORING_BLOCKED` (WAF challenge or a redirect outside the allowed origins; no CAPTCHA bypass), `adapter` / `CHECKOUT_ADAPTER_UNSUPPORTED` (unknown theme or checkout, forbidden mutation attempted by the adapter).
  - Recovery: `CHECKOUT_FLOW_FAILED` closes after 2 consecutive passed **scheduled** runs at least 5 minutes apart (manual runs do not count); coverage incidents close on the next pass.
- **Deviation from the OpenAPI note "store attempt result and domain outbox in one transaction":** the incident evaluation runs inside the same transaction as the result instead of through the outbox — same atomicity, no extra consumer. Notifications still go through the outbox.
- **Scenario definition.** The backend sends step codes, actions and the URL each navigation step targets; selectors and the mapping of blocked operations to concrete requests live in the versioned worker adapter (`woocommerce-payment-form@1`). Network policy: the store origin plus explicitly configured gateway/CDN origins; blocked operations: placing an order, Classic and Store API checkout submit, order-pay, capture, refund, any unknown payment mutation.
- **Result validation.** Strict whitelist of keys, scalar values only, no paths with query strings or fragments, known error codes only; anything else is `422` — the backend refuses unsanitized diagnostics instead of trusting the worker.

## Not covered yet

Worker (Step 52), screenshots/artifacts and the signed synthetic marker with Blocks draft cleanup (Step 53), corroboration for critical severity, maintenance windows, the extra run after a financial anomaly, plan quotas.
