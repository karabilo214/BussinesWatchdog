# Implementation Step 42 Checklist: Store Domain Verification

Stage: ADR 0001 gap closure (store verification)
Spec references: `spec/Business-Watchdog-TZ.md` sections 8 (step 5: challenge via connector REST endpoint or DNS; browser jobs forbidden before verification), 20 (SSRF rules), 27; `spec/contracts/ui-api-catalog.md` §3 (verify / verification, exact hostname, platform-neutral `plugin_challenge`).
Closes: ADR 0001 "Store Verification API Issues Challenges Without External Proof Check".

## Scope

- [x] Migration: `store_verifications.attempts`, `last_checked_at`, `last_error_code`.
- [x] `StoreVerificationService`: HMAC-derived challenge (hash stored), instructions, DNS TXT and connector checks, verified → store `verified_at` + `onboarding→active` + audit, URL-change failure, expiry.
- [x] `DnsClient` (`SystemDnsClient` via `dns_get_record`), `PublicDestinationPolicy`, `SafeHttpFetcher` (public IPs only, IP pinning, no redirects, timeout, size cap).
- [x] `POST /stores/{id}/verification/check` (admin, throttled) and `stores:check-verifications` on the scheduler.
- [x] Heartbeat returns the pending connector challenge (`store_verification`).
- [x] `PATCH /stores/{id}` rejects `browser_enabled=true` / `status=active` for unverified stores (`store_not_verified`).
- [x] `PublicHttpsUrl` rule on store create/update (+ ru/en/de messages).
- [x] Fixed during testing: verification id was not mass-assignable, so the challenge was derived from a different id than the stored row (now `forceCreate`).
- [x] Fixed during real-network smoke: Guzzle rejects `CURLOPT_PROTOCOLS`; replaced with the `protocols` option.

## Verification

- [x] Full `php artisan test` (PHP 8.4): 259 tests passed (10 new in `StoreDomainVerificationTest`; 3 existing store tests updated for the new behaviour).
- [x] Migration applied on local PostgreSQL 18.
- [x] Real-network smoke of `SystemDnsClient` + `SafeHttpFetcher`: example.com fetched (pinned IP), `localhost` and `127.0.0.1.nip.io` rejected as `unsafe_destination`, an HTTP redirect rejected as `redirect_not_allowed`.
- [ ] Not tested against a real WordPress plugin endpoint (plugin not implemented).

## Not Done In This Step

- Network-level egress proxy for the browser worker (D0/D4).
- `PATCH` base_url reverify flow cancelling pending check jobs (no check jobs exist yet).
