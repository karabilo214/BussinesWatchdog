# ADR 0012: Synthetic Marker, Blocks Draft Cleanup and Browser Egress Proxy

Date: 2026-10-09

Status: implemented in Step 53.

## Synthetic marker (spec §19)

- The backend issues a token per lease: `v1.<base64url {i: connector integration id, r: run id, e: expiry (absolute deadline + 60 s), k: credential key id}>.<HMAC-SHA256>`, signed with `HMAC(connector secret, "bw-synthetic-v1")` — a key derived from the store connector's own pairing secret, so only that store's plugin can verify it and the HMAC request-signing key is never reused directly. It is returned once in the lease, never stored or logged. No connector → no token.
- The worker sends it as `X-BW-Synthetic` only on requests to the store origin, never to gateways or third parties.
- The plugin trusts nothing but a valid signature for its current connection and key id that has not expired. Then it: marks orders created or updated in that request with `_bw_synthetic_run`, flags their events `is_synthetic: true`, ignores the request for payment-attempt monitoring, and records the last verified run in diagnostics (`last_synthetic_check`).
- During a credential rotation the plugin may not have the key the backend signed with yet; that run is then simply unmarked (its drafts are left for WooCommerce's own draft cleanup).

## Blocks draft cleanup

- Every 15 minutes the plugin deletes, through WooCommerce (`$order->delete(true)`), only orders that are still `checkout-draft`, carry the synthetic mark and are older than 10 minutes. Customer drafts and any non-draft order are never touched (integration test + matrix e2e). Deleting a draft the backend never saw sends no event.
- Matrix finding (Step 53 run): the Blocks check created one checkout draft on WooCommerce 8.9 and 9.9 — it was marked with the run id and removed by the cleanup while the customer draft stayed; WooCommerce 11.2 created no draft during the check; 6.0 and 7.9 have no Blocks checkout page in the matrix.

## Egress proxy (spec §19)

- `infra/docker/egress`: Squid on Debian 12, non-root, read-only, all capabilities dropped. Only `CONNECT` to port 443 leaves; Squid resolves the destination itself and refuses private, loopback, link-local, metadata, CGNAT, multicast, documentation and IPv6 local ranges on the resolved address, so names that resolve to internal addresses (DNS rebinding) are refused too. No cache, no `X-Forwarded-For`, query strings stripped from the log.
- Compose: the worker sits only on two internal networks — `browser-api` (to nginx) and `browser-egress` (to the proxy); only the proxy also joins `browser-internet`. The worker cannot reach PostgreSQL, Redis or anything else directly.
- Chromium uses the proxy for everything including loopback (`<-loopback>`); with `NODE_ENV=production` the worker refuses to start without `BW_EGRESS_PROXY`.
- `infra/scripts/egress-test.sh` (`make egress-test`) checks: public HTTPS allowed; metadata IP, plain HTTP, non-443 port, a public name resolving to 127.0.0.1, a container name and RFC1918 refused. Also verified with Chromium from the worker image.
