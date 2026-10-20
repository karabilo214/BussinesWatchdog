# Implementation Step 48 Checklist: Provider Coverage Gating

Stage: D4 reconciliation (ADR 0006, planned work item 1)
Spec references: `spec/Business-Watchdog-TZ.md` §10.1 (Stripe optional; without an independent provider money checks are `unknown/provider_not_connected`; provider-authority events only from provider credentials; re-evaluate on connect/revoke), §14, AGENTS.md invariants (store-reported paid marker is not proof of capture; unknown ≠ zero).

## Scope

- [x] `ProviderCoverage::isConnected()` — the store has an `independent_provider` integration with status `active` (degraded/revoked/disabled do not count).
- [x] `OrderReconciliationService`: without a provider, a supported order gets one `MONEY_UNSUPPORTED` finding with status `unknown` and reason `provider_not_connected`; the provider-dependent rules are not evaluated; the run's `coverage_snapshot` records `provider_connected`.
- [x] `MoneyIncidentCorrelator` is unchanged: `unknown` neither opens nor auto-resolves incidents, so no false incidents/notifications and no false "fixed".
- [x] `UnmatchedPaymentScanner` and `GET /stores/{id}/unmatched-payments` consider only `independent_provider` captures; the scanner produces no findings without a provider.
- [x] `PaymentAllocationService` rejects capture/refund transactions that are not `independent_provider` (`allocation_source_not_independent`).
- [x] Ingest rejects events whose `data.source_authority` is `independent_provider` when the credential's integration is `store_reported` (`source_authority_not_permitted`, result `invalid`, nothing stored).
- [x] `StoreReconciliationRequeue` marks the 90-day order window and the store unmatched-payment scan dirty; used by the nightly sweep and by revoking an `independent_provider` integration (reason `provider_coverage_changed`). The provider-connect flow will call it when the Stripe connector is built.

## Verification

- [x] `ProviderCoverageTest` (4 tests) and an ingest authority test.
- [x] SQLite: 264 passed + 4 PostgreSQL-only skipped; PostgreSQL 18: 268 passed.

## Not Done In This Step

- Stripe read-only connector (dashboard key form, restricted-key check, sync, webhooks) — ADR 0006 item 2.
- Incident state "observation stopped" for incidents that stay open after a provider is revoked (currently they stay open with the last evidence; never auto-resolved).
