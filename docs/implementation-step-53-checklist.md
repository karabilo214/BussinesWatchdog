# Implementation Step 53 Checklist: Synthetic Marker, Draft Cleanup, Egress Proxy

Stage: P0 (spec §19; ADR 0012)

## Scope

- [x] Backend `SyntheticMarker`: per-lease token signed with a key derived from the store connector secret; in the lease only, never stored.
- [x] Worker sends `X-BW-Synthetic` to the store origin only; requires `BW_EGRESS_PROXY` in production; Chromium proxies loopback too.
- [x] Plugin `SyntheticRequest` (signature, connection, key id, expiry), `SyntheticOrders` (mark orders, `is_synthetic` events, skip payment attempts, `last_synthetic_check` diagnostics, cleanup of own old drafts every 15 min), WP-CLI `cleanup-synthetic`.
- [x] Egress proxy image (Squid, CONNECT 443 only, private ranges refused after resolution), Compose network isolation, `make egress-test`.
- [x] ADR 0010/0011 marked as confirmed by the owner.

## Verification

- [x] Backend: marker test (verifiable with the connector secret, bound to connector/run/expiry, not persisted); SQLite 299 passed + 4 skipped, PostgreSQL 18 303 passed.
- [x] Worker unit tests 13/13 (incl. production config rules).
- [x] Plugin integration tests (marker verification, marking, cleanup only of own old drafts) on all six targets.
- [x] Browser e2e on all six targets with the plugin paired: marker verified by the plugin for every run, customer draft kept, no orders and no payment attempts created.
- [x] Egress test: 7/7 cases; Chromium from the worker image through the proxy reaches public HTTPS only.

## Not Done In This Step

- Screenshots and artifacts (Step 54).
