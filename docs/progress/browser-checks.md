# Progress: Browser checks

Browser check backend, Playwright worker, synthetic marker, egress proxy, redacted screenshots.

### Step 51: Browser Check Backend (Spec §18–20, ADR 0010)

Status: complete for the backend; the worker follows in Step 52

Added:

- Browser check tables, worker credentials, `browser:schedule`, internal lease/heartbeat/result API with fencing and expiry recovery.
- Outcome policy (two site failures confirm; worker problems never do), checkout and coverage incidents with scheduled-pass recovery, notifications (ru/en/de).
- Strict validation of sanitized worker results; user API for the scenario, manual runs and run history; OpenAPI updated.
- `docs/adr/0010-browser-check-backend.md`, `docs/implementation-step-51-checklist.md`.

Verification:

- SQLite 298 passed + 4 skipped; PostgreSQL 18 302 passed.

Remaining:

- Worker (Step 52); artifacts, synthetic marker, Blocks draft cleanup (Step 53).

### Step 52: Browser Worker (Node + Playwright, ADR 0011)

Status: complete; real browser checks reach the payment form on all six WooCommerce targets without placing an order

Added:

- `apps/browser-worker`: lease loop, WooCommerce adapter (Classic + Blocks), network policy (cart-only mutations, order/pay/capture/refund blocked), sanitized diagnostics, Docker image (sandbox on, read-only, no capabilities), Compose service, unit tests.
- `tests/matrix/browser-e2e.sh` (`make worker-e2e`) with the real backend; the matrix mu-plugin can disable its test gateway for the no-payment-methods case.
- `docs/adr/0011-browser-worker.md`, `docs/implementation-step-52-checklist.md`, compatibility table.

Verification:

- Worker unit tests 10/10; browser e2e PASS on all six targets with unchanged order counts; backend suites green.

Remaining:

- Egress proxy, artifacts, synthetic marker, Blocks draft cleanup (Step 53).

### Step 53: Synthetic Marker, Blocks Draft Cleanup, Egress Proxy (ADR 0012)

Status: complete

Added:

- Backend synthetic marker in the lease; worker sends it to the store origin only; plugin verifies it, marks orders, flags synthetic events, skips payment-attempt monitoring and cleans only its own old checkout drafts.
- Squid egress proxy (`infra/docker/egress`), Compose isolation of the worker, `make egress-test`; the worker refuses production without the proxy.
- `docs/adr/0012-synthetic-marker-and-egress.md`, `docs/implementation-step-53-checklist.md`.

Verification:

- Backend SQLite 299 + 4 skipped, PostgreSQL 303; worker 13/13; plugin integration and browser e2e on all six targets (marker verified per run, no orders, no payment attempts); egress 7/7.

Remaining:

- Screenshots/artifacts (Step 54).

### Step 54: Check Artifacts — Redacted Screenshots (ADR 0013)

Status: complete; browser checks P0 done (Steps 51–54)

Added:

- `artifacts` table and private S3 disk; lease-bound internal upload with size/signature/SHA-256 checks; 60-second user links; daily purge; OpenAPI.
- Worker: masked viewport screenshot on failures, focused on the failing step.
- `docs/adr/0013-check-artifacts.md`, `docs/implementation-step-54-checklist.md`.

Verification:

- Backend SQLite 302 + 4 skipped, PostgreSQL 306; worker 13/13; browser e2e on six targets with S3Mock; screenshot inspected.
