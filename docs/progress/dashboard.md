# Progress: Customer dashboard

Palette, dashboard scaffold and workspaces, store/incident/reconciliation/check/notification screens, overview, account and team, MFA.

### Design: Corporate Palette (ADR 0015)

Status: complete; palette frozen by owner decision

Added:

- Seven OKLCH-generated 11-step scales (brand, ink, success, warning, danger, info, unknown), every semantic text/background pair WCAG 2.1 AA.
- `apps/frontend/design/palette.json` (source), `apps/frontend/src/styles/palette.css` (Tailwind v4 `@theme`, default colours disabled, semantic tokens), `docs/design/palette.md` (values, contrast, status mapping), `scripts/check-palette.mjs` + `make frontend-palette-check` (fingerprint, CSS drift, defaults disabled).
- Preview page published for the owner.

### Step 56: Dashboard Scaffold (ADR 0016)

Status: complete

Added:

- `apps/frontend`: Vue 3 + TypeScript + Vite 8 + Tailwind 4 (pinned, Node 24 LTS), Sanctum session client, i18n ru/en/de, router with auth guard, layout, login, store overview with coverage cards.
- Tests: contract states ↔ colours/labels, translation parity, API client (CSRF, headers, errors, 401), store card; Chromium smoke against the real backend (`make frontend-smoke`).
- Makefile targets `frontend-install/dev/check/smoke`.

Verification:

- `make frontend-check` green (12 tests, build); smoke 8/8 with screenshots inspected; backend suites green.

### Step 57: Frontend Workspaces (ADR 0017)

Status: complete

Added:

- `apps/frontend` as an npm workspace: `customer/` (`/app`, the Step 56 project), `control/` (`/owner`, `/admin` placeholders, `/platform-api` proxy), `public/` (vite-ssg prerendered site in ru/en/de), shared `packages/design|ui|i18n|api-client`.
- Makefile targets `frontend-dev-control`, `frontend-dev-public`; palette check moved to `packages/design`.
- Root npm scripts use `node "$npm_execpath"`: a bare nested `npm` resolved to an old npm 5 in an ancestor `node_modules/.bin`, which ignored `-w` and recursed until the machine froze (the previous session ended this way).

Verification:

- `make frontend-check` green (customer 11, control 2, public 3 tests, builds, prerender); customer smoke 8/8; backend SQLite 309 + 4 skipped.

### Step 58: Dashboard Store Screens

Status: complete

Added:

- Customer app: "Add store" form (`/app/stores/new`: name, HTTPS address, time zone, currency; locale from the UI) and the store page (`/app/stores/:id`): coverage summary, store plugin panel (connectors with status, last signal and version; one-time connection code with the service URL and WooCommerce steps, list refreshes until the plugin connects; disconnect with an explicit confirmation), domain confirmation (via the plugin — only when a connector exists — or a DNS TXT record; instructions, translated reason codes, polling while pending, "check now"), settings (name, time zone, currency with `If-Match`, a conflict reloads the store) and pause/resume monitoring.
- Management actions are shown only to owner/admin of the active tenant (the backend still enforces roles); server texts are never shown, errors map from status/code to translations (ru/en/de).
- Backend: while a verification is pending, `GET /stores/{id}/verification` and `.../verification/check` also return `instructions`, so DNS values survive a reload; `POST /stores/{id}/pairing-codes` returns `service_url`. OpenAPI and catalogue §11 updated.
- `CoverageList` extracted from `StoreCard`; the store name on the overview links to the store page.

Verification:

- `make frontend-check` green (customer 21 tests, control 2, public 3, builds, palette).
- `make frontend-smoke` 13/13 against the real backend: store page, DNS confirmation start, instructions after reload, manual check reason, connection code, adding a store and saving settings, German mobile layout without horizontal scroll; failed requests are now checked by URL (only the expected 401/422/404 are allowed). Screenshots inspected.
- Backend: SQLite 310 + 4 skipped, PostgreSQL 18 314 passed.
- Not tested: a real plugin pairing through the dashboard and a real DNS record (the smoke store uses a non-existent `.example` domain).

### Step 59: Dashboard Incident Screens

Status: complete

Added:

- Customer app `/app/incidents`: Active (open + acknowledged) / Resolved tabs, store and severity filters kept in the URL, cursor "show more"; the incident badge on the overview and the store page opens the store's incidents.
- `/app/incidents/:id`: severity/state, last good / first bad (with the "somewhere between" note), verified discrepancy only for money incidents, advice per title code, facts (findings with expected/found/difference per order, plain-language signal evidence for payment attempts, plugin connection and browser checks, masked screenshot of the last check on demand), timeline with comments (plain text), actions: acknowledge and resolve with a reason (`If-Match` revision; a conflict reloads), snooze notifications 1 h–7 d with a reason and lift it, recheck (money: reconcile the orders behind the findings or the unmatched-payment scan; checkout: manual run of the failed run's scenario).
- Money is formatted from minor-unit strings with the exponent via `Intl.NumberFormat` on decimal strings, never JS Number (tested beyond 2^53); unknown amounts are shown as unknown, not zero.
- Stable error codes are translated (`errors.<code>`); a test fails if any title code the backend can raise lacks a title or advice.
- Backend: `GET /incidents` newest first, `state` accepts a comma-separated list; `Incident.currency_exponent` from the linked finding; `GET /incidents/{id}` adds `findings` (with order display number) and `active_suppression`. OpenAPI and catalogue §11 updated.

Verification:

- `make frontend-check` green (customer 31 tests).
- `make frontend-smoke` 17/17: badge → store incidents → incident page, comment with HTML shown as text, acknowledge, snooze and lift, resolve with a reason, Resolved tab, German mobile layout; screenshots inspected.
- Backend: SQLite 311 + 4 skipped, PostgreSQL 18 315 passed.
- Not tested in the browser: money incidents with findings and the check screenshot (covered by unit and API tests only; the smoke incident is a seeded payment-attempts incident).

### Step 60: Dashboard Reconciliation Screens

Status: complete

Added:

- Customer app `/app/reconciliation`: store picker, coverage warning when money cannot be reconciled (no provider / store not connected / stale data — results are "unknown", not "fine"), Discrepancies tab (current state by default or full history; filters result, check, currency, date range, all kept in the URL; neutral check names, order links, expected/found/difference) and Payments-without-an-order tab (provider captures with the provider operation ID, candidates with their confidence, manual match of the whole capture with a reason), store-wide "find payments without an order".
- `/app/orders/:id`: order total, captured at provider, refunds in store and at provider kept separate (unknown stays unknown), store "paid" marker shown as a store claim, latest reconciliation with "reconcile order", captures counted for the order with revoke (reason), store and provider refunds, refund links with revoke and a manual refund link (amount = the smaller unlinked remainder, BigInt arithmetic), order revisions.
- Incident evidence reuses the findings table and links orders.
- `newIdempotencyKey` falls back to `crypto.getRandomValues` outside secure contexts (found by the smoke over plain HTTP: every Idempotency-Key mutation failed in the browser before reaching the API).
- Backend: findings newest first (the contract said so, the code returned oldest first) with `order_display_number` and `current=1`; unmatched payments carry `external_operation_id`, `currency_exponent`, candidate `display_number`/`currency_exponent`; order detail findings come from the latest run only and `refund_transactions` include unlinked provider refunds of the payments allocated to the order. OpenAPI and catalogue §11 updated.
- Smoke seeds a provider, a paid order without a capture and an unmatched capture; failed smoke steps now save `failure.png`.

Verification:

- `make frontend-check` green (customer 39 tests).
- `make frontend-smoke` 21/21, run twice: discrepancy list, manual match, order recheck shows the capture and "matches", revoke with a reason, German mobile order page; screenshots inspected.
- Backend: SQLite 313 + 4 skipped, PostgreSQL 18 317 passed.
- Not done: CSV export (no export API yet), per-currency discrepancy totals, partial amounts for manual matches (the UI links whole captures / the full unlinked refund remainder). Tested with seeded data only, not a real Stripe account.

### Step 61: Dashboard Checkout-Check Screens

Status: complete

Added:

- Customer app `/app/checks`: store picker; readiness (domain confirmed, store active, checks enabled for the store with an on/off switch, scenario set up and enabled — scheduled checks run only when all are met); scenario panel (create from the test product page, cart/checkout detected automatically or set, interval 5 min–24 h, enable/disable with the scenario version, advanced: allowed external origins and synthetic shipping country/postcode; what is checked, what is not covered, "nothing is bought"); run history with "run now" (only when ready), auto-refresh while a run is queued or running.
- `/app/checks/runs/:id`: status with a plain-language note (inconclusive/blocked/cancelled are not store failures), times, attempts with location and browser, steps with their status and error, masked screenshot on demand, sanitized technical details, cancel an active run with a reason. Incident evidence links to the run.
- Contract tests: every run/attempt/step state in OpenAPI has a colour and a label in ru/en/de and only "passed" is green; every worker/backend error code is explained.
- Fixed a request race on the checks, incidents and reconciliation lists: a slower response for a previous store or filter could overwrite the current one (seen once in the smoke as a missing "enable checks" button); stale responses are discarded, with a test that fails without the fix.
- Smoke: seeds a verified "Smoke Check Shop" with a disabled scenario and a failed run with steps; clears the local rate limiter before starting (back-to-back runs hit the 5 logins/min limit).

Verification:

- `make frontend-check` green (customer 49 tests).
- `make frontend-smoke` 26/26, six consecutive runs: readiness, enable scenario, change interval, run now → queued → cancel with a reason, failed run steps and details, German mobile layout; screenshots inspected.
- Backend unchanged in this step (last runs: SQLite 313 + 4 skipped, PostgreSQL 18 317).
- Not covered in the browser: a real worker run and the screenshot of a run (no worker or object storage in the smoke); scenario creation is covered by unit tests.

### Step 62: Dashboard Notification Screens

Status: complete

Added:

- Customer app `/app/notifications` (owner/admin manage; everyone sees the log): add an email recipient with preferences (minimum severity, email language, time zone, quiet hours, critical during quiet hours — owner only, recovery messages, all or selected stores); confirm with the mailed 6-digit code or request a new one; enable/disable; test email; settings; change address (requires a new confirmation). Channel state: receiving / disabled / awaiting confirmation / emails not delivered (from channel health).
- Delivery log with channel and state filters: kind, recipient, time, attempts, next attempt, translated error, incident link; "delivery unknown" is explained (not resent to avoid duplicates) and shown as a warning, not as delivered.
- Backend: `POST /notification-channels/{id}/verification-code` — a new code to the saved address of an unverified channel, once a minute (before, a new code needed an address change); the contract now lists `Idempotency-Key` on channel creation and `content`/`created_at` of deliveries. Catalogue §11 updated.
- Contract tests: every delivery state has a colour and a label (only "sent" green); every delivery error and channel rejection code from the backend is translated.
- Smoke runs real email: Mailpit receives the confirmation code, the browser test reads it through the Mailpit API, and a background `notifications:deliver` loop delivers the test email.

Verification:

- `make frontend-check` green (customer 55 tests).
- `make frontend-smoke` 30/30, twice: add recipient, confirm with the code from the real email, test email delivered by the worker (in Mailpit and in the log), quiet hours saved, German mobile layout; emails and screenshots inspected.
- Backend: SQLite 314 + 4 skipped, PostgreSQL 18 318 passed.
- Not done: Telegram (P1), deleting a recipient (disable instead), daily digest/reminders. The mobile navigation with five items takes a lot of height; a compact menu is a follow-up.

### Step 63: Dashboard Overview and Mobile Menu (ADR 0018)

Status: complete

Added:

- Backend `GET /api/v1/overview`: active incidents by severity and the 5 latest, verified discrepancies of active money incidents summed by the database per currency and component (never across currencies or kinds), the number of active money incidents without a verified amount, the 5 latest finished checks. Decisions in `docs/adr/0018-overview-discrepancy-totals.md` (for owner review). OpenAPI and catalogue §11 updated.
- Overview page: verdict banner — "problems found" with active incidents; "no problems found" only when every store has a fresh connector, money reconciling, payment attempts observed and passing checkout checks; otherwise "data incomplete" with the number of stores with gaps. Tiles: active incidents by severity, verified discrepancies per currency and kind labelled "not losses" with the unknown-amount count, last successful checkout check; latest incidents, recent checks, then the store cards.
- Navigation below 768 px collapses into a "Menu" button with a panel (sections, user, language, sign out); the current section stays highlighted on child pages (store, incident, order, check run) and the panel closes after navigation.
- Smoke seeds money incidents through the real correlator, checks the verdict and the EUR capture total, and uses the menu at mobile width.

Verification:

- `make frontend-check` green (customer 59 tests: verdict rules, totals never added across kinds, menu behaviour).
- `make frontend-smoke` 32/32 twice; screenshots inspected.
- Backend: SQLite 315 + 4 skipped, PostgreSQL 18 319 passed (overview totals checked above 2^53 on both).
- Limits (ADR 0018): live and test-mode incidents are not separated; a sum above the signed 64-bit range fails on SQLite only.

### Step 64: Account and Team (ADR 0019)

Status: complete

Added:

- Backend: password reset request/complete (same answer for unknown addresses, one-time 60-minute link stored as a hash, ends every session, confirms the address), email confirmation (signed 24-hour link sent at sign-up, works without a session, resend once a minute), `PATCH /auth/me` (name, language), `POST /auth/password` (needs the current password, ends other sessions), team: `GET/PATCH/DELETE /memberships`, `GET/POST /invitations`, revoke, public lookup, accept, and sign-up through an invitation (joins the team, no new tenant). Role rules, audit and limits in `docs/adr/0019-account-and-team.md` (for owner review). Mail texts in ru/en/de (`lang/*/account.php`).
- Fixed: a request without access to an active team (no team in the session or membership removed) returned HTTP 500; it now answers `403 tenant_forbidden` (an old test had asserted the 500). The email confirmation route sat behind the SPA-session middleware, so a link opened from an email was rejected — found by the smoke, moved out.
- Customer app: "Forgot password?" → request page → reset page from the email; banner "confirm your address" with resend and the result notice after the link; Settings → Profile (name, language — also switches the UI, address state, password change) and Team (members with role selects only where allowed, remove/leave with confirmation, invite by email with allowed roles, pending invitations with revoke); invitation page (accept when signed in with the invited address, warning for another address, sign-in for an existing account, account creation for a new person). Header name links to settings; mobile menu has Settings.
- Smoke (real email through Mailpit): the owner confirms the address from the email; invites an operator; in a separate browser the operator creates an account from the invitation email and later restores a forgotten password from the reset email.

Verification:

- `make frontend-check` green (customer 66 tests: password rules, team permissions, every invitation state, every account rejection code translated).
- `make frontend-smoke` 36/36, five consecutive runs; emails and screenshots inspected.
- Backend: SQLite 327 + 4 skipped, PostgreSQL 18 331 passed (account 6 and team 6 new feature tests).
- Not done: MFA, ownership transfer, several teams per user in the dashboard (create/switch), per-device session list.

### Step 69: Two-Factor Authentication and Ownership Transfer (ADR 0021)

Status: complete

- Backend: TOTP (RFC 6238, own class tested on the RFC reference values) with keyring-encrypted secret, enrollment confirmed by a code, 10 hashed one-time recovery codes, replay protection by the last used step. Login with MFA answers `mfa_required` without a session; `POST /auth/mfa/challenge` (5 minutes, 5 attempts) signs in. Step-up (password + code) for disabling, new recovery codes and ownership transfer. `POST /ownership-transfer`: owner only, target is a member with a confirmed address, owner ↔ admin swap in one transaction, audited. New dependency `bacon/bacon-qr-code` for the QR SVG. Migration: `users.mfa_*` columns, `mfa_recovery_codes`.
- Dashboard: second sign-in step (app or recovery code, return to the password on expiry), "Two-factor authentication" panel in the profile (QR + key, confirmation, recovery codes with copy/download, new codes, turning off; owners without MFA see a recommendation), "Transfer ownership" on the team page with password and code. ru/en/de.
- Tests: 7 backend tests (RFC vectors, enrollment, sign-in with app and recovery codes, expiry, step-up, transfer with and without MFA). Backend: SQLite 346 + 4 skipped, PostgreSQL 18 350 passed. Customer unit tests 75. `make frontend-smoke` 41/41 with three new steps on the real backend (enable MFA with a computed TOTP code, sign in with a recovery code, transfer ownership to the invited operator); 5 of 6 runs green, one run hung on the reconciliation findings request in an older step (not MFA-related, not reproduced in the next three runs).

### Step 135: Customer Account Logo Links to the Public Site

Status: complete

- Owner's backlog item. The «Business Watchdog» logo in the account header and on the sign-in/registration pages is now a plain link to the public site home in the current interface language (`/ru`, `/en`, `/de` on the same origin as `/app`) instead of the account overview.

Verification: `tests/maintenance.test.ts` +1 (logo href follows the language). Customer 115 tests; frontend check green. Backend SQLite 555 passed, 4 skipped (unchanged).

### Step 161: Tenant Owner Controls Discrepancy Amount Visibility (ADR 0018; Spec §7, §24, §25.3, §26; ACC-02)

Status: complete

- The tenant owner can turn off discrepancy amounts for other team roles under Settings → Team. Existing teams keep the prior visible default. `GET/PATCH /api/v1/money-visibility` reports the setting and effective access; only the owner can change it. Every change increments the tenant config version and is recorded in the tenant audit log.
- The backend masks minor-unit fields in overview totals, incident list/detail/action responses and finding lists/details, including findings embedded in order/payment views. Finding evidence is omitted while amounts are hidden. Counts, status, currency and rule codes remain available; the UI labels masked amounts separately from unknown amounts. Existing and new findings/incident CSV exports are denied to nonowners while the setting is off, including downloads of previously generated files. Platform support views retain their separate permissions.
- Migration `2026_10_16_130000_add_show_money_amounts_to_tenants.php` adds the boolean tenant setting, default true. Updated `contracts/openapi.yaml`, `contracts/ui-api-catalog.md` and the reference `spec/database/schema.sql`. No event contract changed.

Verification: backend SQLite 592 passed, 4 skipped (596 total); PostgreSQL 18 596/596; customer typecheck, 128/128 tests and build. No live browser smoke. The setting governs discrepancy amounts on the named surfaces; underlying order and provider transaction amounts remain subject to their existing role permissions.
