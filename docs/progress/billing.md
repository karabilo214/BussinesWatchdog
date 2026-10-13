# Progress: SaaS billing and owner panel

Plans and entitlements, Paddle adapter, trials, usage, subscription page, platform staff sign-in, plans/prices/discounts in the owner panel, plan change and cancellation, customers and subscriptions, volume packages, pause, add-ons, retention, invoices and payments, refunds.

### Step 97: Plans and Entitlements

Status: complete (plan contents and prices await the owner)

- ADR 0029. Migration `2026_10_11_180000_create_billing_plans_and_entitlements`: `billing_plans`, `billing_plan_versions`, `billing_prices` (`price_kind` base / extra_store), `subscriptions` (plan snapshot, `extra_store_quantity`, trial and past-due dates), `entitlement_overrides`; PostgreSQL CHECK constraints.
- `FeatureCatalog` (7 features, 6 limits, validation of entitlement JSON), `Entitlements` (access state, reasons, `allows`, `limit`), `EntitlementCalculator` (priority: deleting/deleted → no service; suspended → paused manual; paid period; override on top of the paid plan; trial; past-due grace 7 days; paused by billing / onboarding).
- Owner requirement: the price depends on the number of stores. `withStores()` adds bought stores to `max_active_stores` up to `max_extra_stores` and multiplies per-store limits (`monthly_event_budget`, `manual_runs_per_day`); the check interval floor applies to every store.
- Enforcement points: check scheduling and manual runs (`checkout_monitoring`, interval floor; API 403 `feature_not_in_plan`), payment attempt windows, money reconciliation (`unknown` / `not_entitled`), unmatched payments scan, Telegram channels (403), store creation (403 `plan_store_limit_reached`).
- `WATCHDOG_BILLING_ENFORCEMENT`: `pilot` (default, today's behaviour, everything allowed) or `enforce`.
- `GET /api/v1/entitlements` (OpenAPI, both copies). `billing:seed-placeholder-plans` creates draft Free / Start / Pro marked as placeholders.

Verification:

- `tests/Feature/Billing/EntitlementsTest.php` — 14 tests.
- Backend SQLite: 415 passed, 4 skipped. PostgreSQL 18: 419 of 419.

### Step 98: Paddle Billing Adapter

Status: complete in Paddle sandbox (owner decisions 6–8 of ADR 0030 pending)

- ADR 0030. Migration `2026_10_11_190000_create_billing_provider_tables`: `billing_customers` (provider ref only, no profile), `billing_subscription_contracts`, `billing_checkout_sessions`, `billing_event_inbox`, `billing_sync_cursors`; `billing_prices.provider_product_ref`.
- `App\Support\Billing\Paddle`: `PaddleClient` (pinned `Paddle-Version: 1`), `PaddleCatalogSync`, `PaddleCheckout` (local session first, provider call outside the DB transaction, own idempotency by tenant + key and request hash, supersedes pending sessions and cancels their transactions, one live contract per tenant), `PaddleSignature`, `PaddleEventRecorder` (sanitized inbox, duplicate/conflict by event id + payload hash), `PaddleEventPuller` (event log in id order, cursor two minutes behind), `PaddleEventProcessor` (lease, backoff, dead letter, quarantine), `PaddleSubscriptionSync` (current subscription from Paddle, link only via a server-created `origin: api` transaction of our checkout, freshness by `updated_at`, unknown prices -> `unknown` contract without access change). Provider-neutral `SubscriptionProjector` writes the tenant's `subscriptions` row.
- API: `POST /api/v1/billing/checkout`, `GET /api/v1/billing/checkouts/{id}`, `GET /api/v1/billing/subscription` (owner only, `TenantRoles::billingManage`), `POST /api/v1/webhooks/paddle`. OpenAPI (both copies).
- Commands: `billing:paddle-catalog`, `billing:paddle-events` (every minute), `billing:process-events` (every 30 s); `billing:seed-placeholder-plans --test-prices`.
- Real Paddle sandbox: catalog created (Start/Pro base and extra-store prices), checkout through the service, payment with Paddle's test card through Paddle.js in Playwright, events pulled from the event log, subscription projected (`start`, active, 2 extra stores -> 3 stores), checkout confirmed, scheduled cancellation projected as `cancel_at_period_end`. Spike transactions created by hand were quarantined as not linked. A real webhook delivery was not tested (needs a public URL).
- Found on PostgreSQL with real data: non-UUID custom data crashed the session lookup — now validated, and any unexpected processing error retries and then dead-letters instead of blocking the inbox.

Verification:

- `tests/Feature/Billing/PaddleBillingTest.php` — 14 tests (HTTP faked).
- Backend SQLite: 429 passed, 4 skipped. PostgreSQL 18: 433 of 433.

### Step 99: Owner Decisions on ADR 0030 and Our Own Trial

Status: complete

- Owner: prices stay gross for consumers for now (to be checked with a tax adviser); trial is our own without a payment method; policy acceptance waits for the legal pages.
- Migration `2026_10_11_200000_add_trial_consumed_to_tenants`. `TrialService` + `POST /api/v1/billing/trial` (owner, verified email, `idempotency` middleware): once per tenant under the tenant lock, `provider = internal`, `trial_ends_at` from the plan version's `trial_days`; drafts only in the Paddle sandbox. A paid contract replaces the trial row through `SubscriptionProjector`. OpenAPI (both copies).

Verification:

- `tests/Feature/Billing/TrialTest.php` — 3 tests.

- Backend SQLite: 432 passed, 4 skipped. PostgreSQL 18: 436 of 436.

### Step 100: One Trial per Store Domain

Status: complete

- ADR 0031 (owner 2026-10-12: organisation and store domain are checked together; `www.` and the bare host are one domain).
- Migration `2026_10_12_100000_create_trial_store_hosts`: `trial_store_hosts` (unique host → tenant, store, claimed_at), kept after the store or the trial is gone.
- `TrialStoreHosts`: host normalization (lower-case, trailing dot, IDN punycode, one leading `www.`; port/path ignored, subdomains distinct), cross-tenant "claimed by another organisation" lookup, claims.
- `TrialService::start`: besides `tenants.trial_consumed_at`, rejects 409 `trial_store_already_used` when a verified live store of the tenant has a domain claimed by another tenant; claims the domains of its verified stores.
- `StoreVerificationService::markVerified`: while the tenant is on our own trial, a store whose domain is claimed elsewhere fails verification with `trial_store_already_used`; otherwise a verified store of a tenant that consumed its trial claims its domain. Only verified stores claim, so a domain cannot be blocked by typing someone else's address.
- Customer dashboard: the verification panel explains `trial_store_already_used` (ru/en/de). Also added the missing error texts for `feature_not_in_plan` and `plan_store_limit_reached` (Step 97 codes; the notification contract test had been red since then). OpenAPI trial description updated (both copies).

Verification:

- `tests/Feature/Billing/TrialTest.php` — 7 tests (4 new: domain normalization, one trial per verified domain across organisations with subdomains distinct, unverified store does not block but cannot be verified during the trial and can after a purchase, store verified during the trial claims its domain).
- Backend SQLite: 436 passed, 4 skipped. PostgreSQL 18: 440 of 440.
- Customer frontend `npm run check`: 93 tests passed, build completed.

### Step 101: Usage Counter

Status: complete (event budget enforcement awaits the owner, ADR 0032 p. 6)

- ADR 0032. Migration `2026_10_12_110000_create_usage_tables`: `usage_buckets`, `usage_entries` (reference schema; PostgreSQL CHECKs).
- `UsageMeter`: idempotent `consume` by operation key under the locked period bucket, limit check before recording, `current`; day and month periods in the tenant's timezone stored as UTC bounds.
- Manual check runs: `CheckScheduler::runNow` consumes `manual_runs_per_day` last (after eligibility, per-minute and active-run checks); `UsageLimitResponse` returns 429 `usage_limit_reached` with limit/value/used/resets_at and Retry-After.
- `EventIngestor`: accepted non-synthetic events from store-reported integrations are counted into `monthly_event_budget` once per ingest call (duplicates, quarantined, synthetic and provider events are not); no limit yet.
- `GET /api/v1/entitlements` returns `usage`. OpenAPI (both copies): `Entitlements.usage`, `UsagePeriod`, manual run 429 description. Customer error text `usage_limit_reached` (ru/en/de).

Verification:

- `tests/Feature/Billing/UsageTest.php` — 3 tests (daily limit with Retry-After and reset at the tenant's midnight, refused attempts not counted; ledger idempotency and pilot counting; store events per month across a DST change, duplicates/quarantined/synthetic/provider events excluded, API usage).
- Backend SQLite: 439 passed, 4 skipped. PostgreSQL 18: 443 of 443. Customer `npm run check`: 93 passed, build completed.

### Step 102: Subscription Page

Status: complete

- Owner decisions on ADR 0032: the event budget is a soft limit (events are never refused; warning above 80 % and above 100 % with an upgrade offer); manual runs are not returned to the counter.
- ADR 0033. Backend: `BillingCatalog` (sellable states and prices shared by `PaddleCheckout`, `TrialService` and the catalog), `GET /api/v1/billing/plans` for any member (latest sellable version per plan, prices as minor-unit strings, `mode`, `checkout_available`, `trial_available`). OpenAPI (both copies): path and `BillingPlan` schema.
- Customer dashboard `/app/settings/billing` ("Подписка" tab): access state, subscription details for the owner (plan, payment state, trial end, renewal or end date, grace, extra stores, scheduled cancellation), limits and usage with the soft event-budget warning, plan cards (price, extra-store price, limits, features, draft marker, current plan), trial button, purchase with extra stores through Paddle.js; "confirmed" only after the backend returns a paid provider subscription. Members see the plan without actions. ru/en/de texts, billing error texts.
- `tests/e2e/run-billing-live.sh` + `billing-live.mjs`: live check against the Paddle sandbox (fresh verified owner, Start + 1 extra store = 28,00 €, Paddle's test card, confirmation by the backend, subscription shown as paid).

Verification:

- Backend: `tests/Feature/Billing/BillingPlansTest.php` (new). SQLite 440 passed, 4 skipped; PostgreSQL 18 444 of 444.
- Customer: `tests/billing.test.ts` 5 tests; `npm run check` 98 passed, build completed; `make frontend-smoke` 42/42.
- Live Paddle sandbox: `run-billing-live.sh` PASS (first run found that the checkout is confirmed by the transaction event a moment before the subscription is projected; the page now waits for the subscription).

### Step 103: Control Panel Sign-in for Platform Staff

Status: complete

- Owner decision (2026-10-12): everything sold — packages, prices, billing periods (any "every N days/weeks/months/years"), limits, trial days, discounts — is managed in the owner's panel. Order: sign-in (this step), plans and prices, discounts.
- ADR 0034. Migration `2026_10_12_120000_create_platform_staff_tables` (`platform_staff`, `platform_audit_log`, PostgreSQL CHECKs). `PlatformStaff`, `PlatformAudit`, `PlatformCapabilities`, `PlatformSession`.
- `/platform-api/v1` (`routes/platform.php`, middleware group `platform`): own session cookie and CSRF cookie (`UsePlatformSession` restores the session config after the request, `ValidatePlatformCsrfToken`), guard `platform`, `RequirePlatformStaff` (active staff, authenticator on, idle/absolute/permission-version limits, capabilities). Endpoints: `csrf-cookie`, `auth/login`, `auth/mfa`, `me`, `auth/logout`; rate limiters `platform-login`, `platform-mfa`. OpenAPI (both copies) with `StaffSession`; the route contract test now covers `/platform-api`.
- `php artisan platform:create-owner <email>`: creates or attaches the account, hidden password, enrollment of the authenticator on the server, recovery codes once, system audit entry.
- `@bw/api-client` `configureCsrf()`; control app: two-step sign-in, `/owner` layout with payment mode, staff and role, section placeholders, redirect back after sign-in, expired-session handling; ru/en/de.
- Found while testing: an unauthenticated `/me` call between password and code ended the session and dropped the pending sign-in — the session is now ended only when a signed-in user is no longer valid.

Verification:

- `tests/Feature/Platform/PlatformAuthTest.php` — 7 tests (two-step sign-in and audit, customer session/account refused, own cookies, no authenticator / disabled, idle 30 min / 8 h / permission change, rate limit, bootstrap command with enrollment).
- Backend SQLite: 447 passed, 4 skipped; PostgreSQL 18: 451 of 451. Control `npm run check`: 4 passed, build completed; customer: 98 passed.
- `apps/frontend/control/tests/e2e/run-login.sh` (real backend + Vite + Chromium, real TOTP): redirect to sign-in, wrong password refused, password + code open `/owner/plans`, only `bw-platform-session` and `BW-PLATFORM-XSRF` cookies, sign-out ends the session — PASS.

### Release README and Authenticator Re-encryption

- `README.md` rewritten as the release and operations guide: environment variables, migrations, scheduler, first platform owner (`platform:create-owner`), browser worker token, Telegram webhook, Paddle setup, mail check, plugin ZIP and release checks, frontend builds, maintenance commands, local tests and live checks. `CLAUDE.md`: every new command, variable or manual step goes into the README right away (owner request 2026-10-12).
- Found while writing it: `security:reencrypt-secrets` did not re-encrypt users' authenticator secrets, so removing an old key after rotation would have locked out everyone with two-factor sign-in, including platform staff. The command now also re-encrypts `users.mfa_secret_ciphertext`; test `test_reencrypt_command_moves_authenticator_secrets_so_codes_keep_working`.

### Step 104: Plans, Prices and Billing Periods in the Owner Panel

Status: complete (discounts are the next step)

- ADR 0035. Migration `2026_10_12_130000_add_billing_interval_count`: prices billed every 1–365 days/weeks/months/years (`billing_interval_count`, unique key includes the period); trial 0–365 days.
- `PlanAdmin` + `PlanController` (`/platform-api/v1/plans`, `plans/{id}/versions`, `plan-versions/{id}` PATCH, `…/prices`, `plan-prices/{id}` DELETE, `…/publish`, `…/retire`): create (immutable code), edit drafts (ru/en/de texts, trial, features, limits with minimums), prices (Paddle currencies, ISO exponent, minor-unit strings, any period), new version from the latest, publish with reason + authenticator code (Paddle product per plan shared by versions, prices with the billing cycle created before the local switch; previous version retired for new buyers, subscribers untouched), retire. Capabilities `plans.read` / `plans.manage`; every change audited.
- Customers see only published versions and prices in every environment; the sandbox seeder publishes placeholder plans and their test prices (also prices left as drafts by older runs — found by the live purchase rerun). `PaddleCheckout` matches the extra-store price by period too. Customer catalog DTO: `interval_count`, `description`; the subscription page prints any period ("каждые 2 недели", "каждый 21 день").
- Control app: "Тарифы и скидки" list and version editor (content, features, limits with "unlimited", prices with amount parsing, period preview, publish/retire confirmation with code). `formatMinor` moved to `@bw/ui` together with the new string-only `parseMinor`.
- README: plan management replaces the catalog command in the release steps; `run-live.sh login|plans`.

Verification:

- `tests/Feature/Platform/PlanAdminTest.php` — 4 tests (draft → prices for any period → step-up → Paddle billing cycle → customer catalog; new version retires the old one without moving subscribers; Paddle failure keeps the draft; roles). Billing tests moved to published fixtures; seeder test.
- Backend SQLite: 457 passed, 4 skipped; PostgreSQL 18: 461 of 461. Control: 9 tests; customer: 99 tests; builds pass.
- Live: `apps/frontend/control/tests/e2e/run-live.sh plans` — PASS in the Paddle sandbox (plan created in the browser, 45,50 € every 2 weeks + 5,00 € per extra store, wrong code refused, published, retired); Paddle API confirms the price `billing_cycle {week, 2}`. `run-billing-live.sh` — PASS after the change. `make frontend-smoke`: 42/42 on the third run; the first two runs failed at different steps of the owner's two-factor section (log-out click, ownership transfer) without backend errors — a flaky part of the smoke scenario, not touched by this step.

### Step 105: Discounts in the Owner Panel

Status: complete

- ADR 0036. Migration `2026_10_12_140000_create_billing_discounts`: `billing_discounts` (percentage in basis points or flat amount with currency, audience code/tenant/automatic, duration once/periods/forever, usage limit, start/end, plans, Paddle id, state; PostgreSQL CHECKs), `billing_discount_tenants`, `billing_checkout_sessions.discount_id`.
- Paddle API verified in the sandbox before coding (discount fields, archiving, a server-created transaction with `discount_id`: 19.00 € − 20 % = 15.20 €).
- `DiscountAdmin` (create in Paddle with `enabled_for_checkout: false`, then locally; change name/text/plans/organisations/dates/limit; archive), `DiscountResolver` (code > organisation's own > best public offer by saving; reasons `discount_code_invalid`, `discount_expired`, `discount_not_applicable`, `discount_used_up`; uses from confirmed checkouts). `PaddleCheckout` takes an optional code, stores the discount and sends `discount_id`.
- API: platform `GET/POST /discounts`, `PATCH /discounts/{id}`, `POST /discounts/{id}/archive` (reason + `confirmation_code`), `GET /tenants/lookup`; customer `GET /billing/discounts/check`, `discount_code` on checkout, `discount` in the catalog and the checkout. OpenAPI (both copies).
- Control app: "Скидки" tab with list (value, plans, organisations, uses, dates, state), creation form (audience, organisation search, percent or amount parsed without floats, duration, limit, dates, plans) and change/archive with confirmation. Customer: promo code field, discount on plan cards, crossed-out and discounted first payment, code sent with the purchase.
- Found on PostgreSQL only: the organisation lookup compared the uuid id with free text — the id is compared only for UUID queries now.

Verification:

- `tests/Feature/Platform/DiscountTest.php` — 4 tests (creation with Paddle terms, step-up, duplicates and validation, change, archive, audit; promo code applicability, expiry and usage limit; organisation discount beats public offers, best saving, start/end, archived; lookup). Control 10 tests, customer 102 tests.
- Backend SQLite: 461 passed, 4 skipped; PostgreSQL 18: 465 of 465 (twice).
- Live in the Paddle sandbox: `run-live.sh discounts` created promo code E2EMV4WAY65 (20 %, 2 periods, Start only, 5 uses) in the browser; `BW_PROMO=E2EMV4WAY65 run-billing-live.sh` applied it (28.00 € → 22.40 €) and paid with the test card — PASS. Paddle: transaction total 22.40 €, discount on it, `times_used` 1, the subscription carries the discount until two monthly periods end.

### Step 106: Stacked Discounts and Per-Period Discount Ledger

Status: complete

- Owner decision 2026-10-12: discounts stack — offer, then the organisation's own discount, then the promo code, each on the amount left by the previous one; each keeps its own duration; before every renewal the server recomputes the next period's combined discount and sets it at Paddle; every period is recorded in a readable form. ADR 0036 updated.
- Sandbox checks before coding: a flat discount lowers a tax-inclusive total by exactly its amount (28.00 € − 5.60 € = 22.40 € for a German buyer); a subscription refuses a one-time discount but accepts `recur: true, maximum_recurring_intervals: 1` with `effective_from: next_billing_period` (next payment 25.00 €, the one after back to 28.00 €).
- Migration `2026_10_12_150000_create_discount_stacking_tables`: `billing_discount_grants` (discounts a purchase got, periods total/used), `billing_discount_applications` (per period: before, saving, after, Paddle discount, status) and `billing_discount_application_lines` (per discount in order); `billing_checkout_sessions.discount_id` removed.
- `DiscountResolver::stack/calculate` (best offer + best personal + code, sequential, half up), `DiscountLedger` (records the purchase, creates the purchase's one-time combined Paddle discount, links grants to the contract when the subscription is linked, `planRenewals` creates the next period's one-period discount and patches the subscription, retries failures, never duplicates a period), command `billing:plan-discounts` every 15 minutes. Checkout returns `discounts` (breakdown); the catalog lists `discounts`; platform `GET /discount-applications`.
- Customer page: every discount line with its saving in order and the total after discounts. Control: "История скидок" tab.

Verification:

- `DiscountTest` — 5 tests (promo code breakdown and Paddle combined discount; offer → personal → code 2800 → 2300 → 1955 → 1655; renewal planning without the one-time code, no duplicate, Paddle failure then retry, history endpoint). Control 11, customer 102.
- Backend SQLite: 462 passed, 4 skipped; PostgreSQL 18: 466 of 466.
- Live in the Paddle sandbox: offer 10 % forever + promo code 20 % for 2 periods created in the panel; purchase 28.00 € → 25.20 € → 20.16 € paid (Paddle total 2016); `billing:plan-discounts` set the second period's combined discount, Paddle's next transaction 20.16 €, the second run planned nothing; ledger rows for periods 1 and 2 match.

### Step 108: Plan Change, Cancellation and Store Capacity in the Customer Dashboard

Status: complete

- Paddle sandbox checks before coding (ADR 0037): subscription preview returns the proration and the next transaction without changing anything; `prorated_immediately` Start + 1 store → Pro + 1 store charged 27.82 € now and 56.00 € at renewal; Paddle has no scheduled item change (only cancel/pause/resume), `do_not_bill` changes items at once and the renewal bills the new items; the proration charge carries no subscription discount and the old next-period combined discount stays on the subscription after an item change; scheduled cancel keeps `active` with `scheduled_change.action = cancel`, `scheduled_change: null` resumes, items can change while a cancel is scheduled; `discount: null` and `on_payment_failure: prevent_change` are accepted.
- Migration `2026_10_12_160000_create_billing_plan_changes`: `billing_plan_changes` (from/to price and extra stores, kind immediate/next_period, status, due now, next period before/after discounts, retained stores, contract version, preview expiry, effective time, idempotency key, error, discounts settled; PostgreSQL CHECKs and one scheduled change per contract) and `stores.capacity_paused_at`.
- `PaddleSubscriptionActions`: preview (same currency and interval; not lower recurring price → immediate with Paddle proration, lower → next period; while a cheaper plan is scheduled only another cheaper plan; retained stores required when the new plan allows fewer stores; our next-period price with the discounts that still apply), confirm (token valid 10 minutes, contract version re-checked, Idempotency-Key; `on_payment_failure: prevent_change`; declined → `payment_failed` and nothing changes; lost answer → `unknown`, never retried), withdraw a scheduled change (paid items back with `do_not_bill`), cancel at period end and resume (optional expected version), all audited; the subscription is read back from Paddle after every action. `billing:plan-changes` (every minute): due scheduled changes take effect, unknown outcomes are resolved by reading the subscription, next-period discounts are re-planned.
- `SubscriptionProjector` keeps the paid plan and stores until a scheduled cheaper plan is due. `DiscountLedger`: next-period discounts only for plans they cover, `replanNextPeriod` (gives the period's uses back, removes the old Paddle discount, plans on the new price), `nextPeriodQuote` for the preview. `BillingCatalog` now owns the sellable base/extra-store price lookup used by checkout and plan change.
- `StoreCapacity`: retained stores are applied when the change takes effect, the others get `capacity_paused_at` (nothing deleted); with more running stores than the limit and no choice the organisation is `capacity_choice_required`; `CheckScheduler` skips paused stores and such organisations, manual runs return 403 `store_capacity_paused`. No limit in pilot mode.
- API: `POST /billing/plan-change-preview`, `POST /billing/plan-change`, `POST /billing/plan-change/cancel`, `POST /billing/cancel`, `POST /billing/resume`, `PUT /billing/retained-stores`; subscription DTO adds `price`, `scheduled_change`, `capacity`; store DTO `capacity_paused_at`. OpenAPI (both copies).
- Customer dashboard: plan cards of a paid subscription — "switch to this plan" / "change number of stores" with extra stores; the change panel shows when it takes effect, the charge now, the next payment before and after discounts, the store choice when needed and confirms; scheduled change with "withdraw"; cancellation explains until when, what stays and that nothing is refunded, optional reason; scheduled cancellation with "resume"; capacity choice panel; store card shows a capacity pause. Texts ru/en/de, new error codes translated.
- Found in the first live run: the page showed "resumed" before it had re-read the subscription, so a check right after the notice still saw the old state; notices now appear after the reload.

Verification:

- `tests/Feature/Billing/PlanChangeTest.php` — 11 tests (upgrade preview/confirm/replay/idempotency conflict/used; expiry, stale contract and superseded preview; declined payment; lost answer resolved by `billing:plan-changes`; cheaper plan kept until the period end then applied; withdraw; retained stores required/invalid, pause only at the period end, nothing deleted, re-choice; scheduler and manual run skip paused stores and wait for a choice; cancel/repeat/resume/version conflict with audit reason; owner only, unchanged, interval, extra stores, past due; next-period discounts re-planned on the new price with a plan-restricted code dropped).
- Backend SQLite: 479 passed, 4 skipped; PostgreSQL 18: 483 of 483. Customer: 107 tests, typecheck, build; palette check ok; dashboard smoke 42/42.
- Live in the Paddle sandbox (`BW_CHANGE=1 run-billing-live.sh`, real test-card purchase Start + 1 store 28.00 €): upgrade to Pro previewed (charged now 21.00 €, next 49.00 €) and confirmed — Paddle transaction `subscription_update` 21.00 € completed, Pro read back as current; Start scheduled for the next period with Pro kept; withdrawn; renewal cancelled at the period end; resumed — PASS (second run, after the notice-order fix).

### Step 109: Customers and Subscriptions in the Owner Panel

Status: complete

- ADR 0038. New capability `tenants.manage` (platform owner only).
- `TenantDirectory`: organisation list (search by name, organisation id or the owner's verified email; filters service state, subscription state incl. none, plan, cancellation scheduled, trial ending before; cursor, 50 per page) with access state and reasons next to the subscription, store capacity, open incidents, active override; organisation card (access, capacity, members, stores as a summary only, billing: subscription with scheduled change and next charge, provider contracts, plan changes, overrides; platform audit of the organisation); subscription list. Commercial fields only with `billing.read`; emails masked (`a***@domain`) unless the viewer is the platform owner; no secrets.
- `TenantActions`: suspend / resume (organisation status only, billing untouched), grant temporary access (published plan version + extra stores until ≤ 180 days, one at a time) / revoke, extend our own trial (later than now and the current end, ≤ 90 days), cancel at period end through `PaddleSubscriptionActions::cancel` (customer audit shows a system actor with `by: platform`), refresh from Paddle. Preview applies database-only actions in a rolled-back transaction and reports access before/after, stores and effective date. Apply needs a reason and an authenticator code; every attempt (denied, failed, succeeded) goes to `platform_audit_log` with access before/after.
- API: `GET /platform-api/v1/tenants`, `GET /tenants/{id}`, `GET /subscriptions`, `POST /tenants/{id}/actions/{action}/preview`, `POST /tenants/{id}/actions/{action}`; OpenAPI (both copies, YAML parsed).
- Control panel: "Клиенты" (filters in the address, search kept out of it because it may hold an email), organisation card with tabs overview / stores / subscription / members / audit and an actions panel (preview → reason + code → confirm), "Подписки"; ru/en/de.
- Found while testing: an override granted "now" was shown as not active (`starts_at` equal to now is not "past"); the audit recorded the access after an action from the calculator's memo (same as before); pressing Enter in the action form bypassed the disabled confirm button — all fixed.

Verification:

- `tests/Feature/Platform/TenantAdminTest.php` — 7 tests (list, filters, search by verified email and id, subscription list; masked contacts and no billing for support, no actions; card without secrets and with a store summary only; suspend preview/denied code/apply/audit/billing untouched/resume; override period, one at a time, revoke; trial extension rules; cancel through the adapter with the customer audit and refresh from Paddle).
- Backend SQLite: 486 passed, 4 skipped; PostgreSQL 18: 490 of 490 (one test first failed on PostgreSQL only because `jsonb` reorders keys — the assertion now compares by key). Control: 17 tests, typecheck, build; palette check ok.
- Live in Chromium against the real backend (`run-live.sh tenants`): organisation found among trials, filter in the address and the search not, card and stores, trial extended by 10 days, service suspended and resumed, every action in the organisation audit — PASS.

### Step 110: Owner Decisions on ADR 0038 and Temporary Access Limits

Status: complete

- The owner accepted ADR 0038 points 4–6: suspension leaves billing untouched (may be revisited), temporary access up to 180 days and one at a time, our own trial extended up to 90 days from now; a provider trial with a payment method is not extended, so its first charge date never moves.
- Fixed: on top of a paid period an override replaced the plan's limits, so a temporary access of a smaller plan lowered the store limit the customer pays for. `EntitlementCalculator::widerLimits` now takes the more generous value of each limit (null is "no limit"; the minimum check interval takes the smaller value, every other limit the larger; a limit the override does not mention stays as the plan has it). Without a paid period the override's own limits apply, as before.
- The action hint in the owner panel says so (ru/en/de).

Verification:

- `EntitlementsTest::test_override_never_lowers_a_limit_of_the_paid_plan`.
- Backend SQLite: 487 passed, 4 skipped. Control: 17 tests, typecheck.

### Step 114: Volume-Based Packages Configured in the Owner Panel

Status: complete

- ADR 0041 (now partly implemented). Order counter `store_order_volumes` filled by `OrderSnapshotProjector` on the first projection of a non-synthetic order placed after the store was added (`OrderVolume::count`); `OrderVolume` figures (this month, three-complete-month average), daily `billing:order-volume` (average, over-the-package mark, one email to owners with the date to choose by, clears when back within), grace days per package version, recommendation of the smallest covering package (individual one last).
- Package settings in the owner panel: plan limit `max_orders_per_month` (in the generic limits list, minimum 1), `order_volume_grace_days` (0–365) and `contact_sales` on the version (publish without a base price allowed for it; copied to new versions).
- Shown: cabinet billing «Order volume» block, order limit in plan cards, individual package card with a contact link and without trial/purchase; owner panel organisation card stores tab (orders this month, average / limit, over since); public pricing page (orders per store row, «Individual» card with «Discuss terms»).
- Mail texts ru/en/de (`account.order_volume_over`).

Verification:

- New `tests/Feature/Billing/OrderVolumeTest.php` (4: whole-month average; over the package → marked, one email with average/limit/date, repeated night sends nothing, summary with grace end and recommendation, back within clears; individual package recommended beyond priced ones, pilot evaluates nothing; pricing page row and individual card), `DomainOutboxDispatcherTest` (an order counts once over two revisions; an order placed before the store was added does not), `PlanAdminTest` (limit, grace days, validation, publish of an individual package without prices, copied to a new version). Owner panel: plan editor saves the limit, grace days and individual price; stores tab shows the volume (control 23 tests). Cabinet: volume block, over notice, recommended individual package and card (customer 108 tests).
- Backend SQLite: 511 passed, 4 skipped; PostgreSQL 18: 515 of 515 (one assertion first failed on PostgreSQL only because `jsonb` reorders keys). Frontend check green. No live browser run in this step.

### Step 115: Pause the Organisation After the Order-Volume Grace Period

Status: complete

- Owner decisions (2026-10-12): after the grace period the service is paused and resumes only after a fitting package is bought; orders placed before the store was added are history; prices and boundaries are set by the owner.
- The pause is on the organisation, because the subscription is: `tenants.order_volume_paused_at`; `EntitlementCalculator` turns an active result into `paused_billing` / `order_volume_exceeded` keeping the package limits, so every existing feature gate stops the service while store data is still received. `OrderVolume` evaluates per organisation (stores over the limit, grace end per store), pauses with a second email (ru/en/de `account.order_volume_paused`) and resumes when no store is over any more, re-queuing every store's reconciliation (`REASON_ORDER_VOLUME_RESUMED`). Immediate resume: `SubscriptionProjector` after a subscription change (after commit) and owner temporary access grant/revoke call `refreshTenant`.
- Cabinet: access badge «Paused: order volume above the package», a pause notice in the «Order volume» block; owner panel: reason label.

Verification: `OrderVolumeTest` 6 (pause after grace with features off and limits kept, stays paused while over, resume after a bigger package and after temporary access with reconciliation re-queued); cabinet test for the pause notice. Backend SQLite 513 passed, 4 skipped; PostgreSQL 18: 517 of 517; frontend check green. No live browser run.

### Step 116: Faster Checks Add-On

Status: complete

- ADR 0041 (faster checks add-on). Migration: `billing_plan_versions.faster_check_interval_minutes`, price kind `faster_checks` (PostgreSQL check updated), `billing_checkout_sessions.faster_checks_price_id`, `billing_subscription_contracts.faster_checks`, `subscriptions.faster_check_interval_minutes`, `billing_plan_changes.from_faster_checks / to_faster_checks / to_faster_checks_price_id`.
- Backend: `BillingCatalog::fasterChecksPriceFor`; `PaddleCheckout::start(..., fasterChecks)`; `PaddleSubscriptionSync` recognises the add-on item; `SubscriptionProjector` keeps the paid add-on until a scheduled removal takes effect; `EntitlementCalculator::withFasterChecks`; plan change preview/confirm/cancel-scheduled/settle/landed check/discount settlement and DTOs carry the add-on; `DiscountLedger` period amount includes it; `PlanAdmin` accepts the price kind and the interval with publish validation; pricing page line.
- Owner panel: price kind «Faster checks», «Faster check interval, min». Cabinet: checkbox in plan cards for purchase and plan change, total, subscription line.

Verification: `PlanChangeTest::test_faster_checks_are_added_at_once_and_removed_at_the_end_of_the_period` (unavailable for a package without it; immediate add with the Paddle item; interval 15 → 5; removal scheduled, kept until period end, then 15 again), `PaddleBillingTest::test_checkout_adds_the_faster_checks_item_only_when_the_package_offers_it`, `PlanAdminTest::test_owner_offers_faster_checks_as_an_add_on_with_its_own_interval_and_price` (validation, publish rule, Paddle price quantity 1, pricing page). Control 23, customer 110 tests (new add-on purchase test; three call expectations updated). Backend SQLite 516 passed, 4 skipped; PostgreSQL 18: 520 of 520. Paddle fixtures only — not run against the sandbox.

### Step 117: History Retention by Package

Status: complete

- Owner's decision (2026-10-13): history older than the package's retention is hidden and deleted 30 days later, so a longer package within that time brings it back.
- `HistoryWindow` (cutoff from `retention_days`, purge date 30 days earlier) applied to: incidents list/detail (open incidents always), check runs list/detail, history findings (current findings always), unmatched payments, notification deliveries, order and payment cards, CSV exports (findings, incidents, transactions). The unmatched-payments list now excludes allocated captures with a subquery instead of an id list (same fix as the scanner in Step 112).
- `HistoryPurger` + daily `billing:history-purge`: closed incidents (not suppressed) with deliveries, activity and signal links; unused signals; past reconciliation findings and runs (current results and findings used by signals kept); finished check runs with attempts, steps and screenshot objects; payment-attempt windows; deliveries. Orders, payments, refunds, transactions and allocations are only hidden.

Verification: `tests/Feature/Billing/HistoryRetentionTest.php` (2: hidden lists and 404 cards, open incident still shown, back with a 365-day package; purge of 70-day-old run and closed incident, 45-day-old kept, current reconciliation result and the order kept, older past result deleted, pilot purges nothing). Backend SQLite 518 passed, 4 skipped; PostgreSQL 18: 522 of 522.

### Step 119: Invoices and Payments

Status: complete

- ADR 0043. `billing_transactions` filled by `PaddleTransactions::project` from transaction events (payload now also keeps discount, grand total, invoice number, billed and created time) and by `syncSubscription` (paged `GET /transactions?subscription_id`), command `billing:paddle-transactions`, also from «Refresh from Paddle»; older reads never overwrite newer states; transactions without our organisation are skipped.
- Cabinet API: `GET /api/v1/billing/invoices`, `GET /billing/invoices/{id}/download` (short-lived Paddle PDF link, 409 when not billed), `POST /billing/payment-method` (Paddle payment-method update transaction for Paddle.js). Cabinet block «Invoices and payments» with PDF download and «Change payment method».
- Owner panel: `GET /platform-api/v1/payments` (billing.read; list with organisation, status filter, month per currency: charged, tax, fee, earnings), page «Payments» instead of the placeholder, payments in the organisation card. OpenAPI (both copies).
- ADR 0042 points 6–8 accepted by the owner.

Verification: `tests/Feature/Billing/BillingTransactionsTest.php` (5: events keep the newest state and skip foreign transactions, paged re-read, owner list/download/payment method with tenant isolation and roles, platform list and month per currency with unknown fees, support forbidden); `TenantAdminTest` fake extended. Control 25 (new `payments.test.ts`), customer 112 (new invoices test). Backend SQLite 530 passed, 4 skipped; PostgreSQL 18: 534 of 534. Real Paddle sandbox (dev database): 10 transactions of earlier test purchases read back with matching amounts (28 €, 22.40 € and 20.16 € with discounts, 21 € proration), tax, fee, earnings and invoice numbers; invoice PDF link returned; payment-method update transaction created; month: 261.56 € charged, 41.76 € tax, 17.58 € fee, 202.22 € earnings. The cabinet and panel pages were checked with tests, not in a live browser run.

### Step 120: Refunds of Our Subscription

Status: complete

- ADR 0044 (owner's choices: billing staff request, the platform owner approves; a refund does not touch the subscription, cancellation at period end is an optional checkbox). ADR 0043 points 5–6 accepted by the owner.
- `billing_refunds` (PG checks on status, kind, positive amount; Paddle adjustment id unique per mode). `PaddleRefunds`: `request` (completed payment of the current mode, reservation under the payment row lock, `full` only for the whole untouched payment), `reject`, `approve` (`POST /adjustments`; partial amounts spread over line items, each up to its total incl. tax; refused → failed, lost answer → unknown; optional cancellation at period end, audited), `project` (adjustment events, refunds only; dashboard refunds recorded), `settleUnknown` (matched by amount among unlinked adjustments, failed after 1 h; run by `billing:plan-changes`).
- Paddle events `adjustment.created`/`adjustment.updated` kept and sanitized (id, action, type, status, transaction, subscription, currency, reason, totals).
- Capabilities `refunds.request` (owner, billing) and `refunds.approve` (owner). Platform API: `GET /refunds`, `POST /payments/{payment}/refunds`, `POST /refunds/{refund}/approve` (reason + authenticator code, step-up throttle), `POST /refunds/{refund}/reject`. `refunded_minor` on payments, the month per currency, the organisation card and cabinet invoices. OpenAPI (both copies).
- Owner panel «Payments»: «Refund» on completed payments with the amount left (exact minor-unit arithmetic), reason and the cancel checkbox; «Refunds» list with status, requester and decider, approve with code / reject; refunded per payment and per month. Cabinet: «refunded X» under the invoice.

Verification: `tests/Feature/Billing/RefundTest.php` (3: request → wrong-role approve forbidden → owner approval with code and the Paddle body; events and dashboard refunds with amounts in payments, month and cabinet; reject frees the amount and a full refund with cancel calls Paddle cancel); `BillingTransactionsTest` month now has `refunded_minor`. Control 27 (`payments.test.ts` +2), customer 112. Backend SQLite 533 passed, 4 skipped; PostgreSQL 18: 537 of 537. Paddle sandbox: partial refund of 1.00 € on payment `txn_01m4qe6xemb3jx82a92hy3w170` with the same body our code sends — adjustment created with status `pending_approval`, item total 100 = 84 net + 16 tax (the item amount includes tax, as assumed); it stayed `pending_approval`, so Paddle's own approval and the `adjustment.updated` event were not observed live. The panel and cabinet pages were checked with tests, not in a live browser run.

ADR 0044 points 6–8 accepted by the owner (2026-10-13); work paused at the owner's request.
