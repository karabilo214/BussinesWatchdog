# Implementation Step 40 Checklist: Pairing And Connector Credential Hardening

Stage: ADR 0001 gap closure (pairing/keyring)
Spec references: `spec/Business-Watchdog-TZ.md` sections 9 (pairing), 27 (encrypted secrets, keyring), 29 (HMAC, key rotation with 24 h draining), 31 (`/health/ready` keyring); `spec/contracts/ui-api-catalog.md` (`POST /integrations/{id}/rotate`, pairing 20/hour/IP).
Closes: ADR 0001 "Pairing Exchange Does Not Yet Include Full Abuse And Keyring Controls".

## Scope

- [x] `pairing-exchange` rate limiter (20/hour/IP, `config/watchdog.php`).
- [x] Audit `integration.pairing_failed` for failures on a known pairing code, without the code.
- [x] `Keyring` (versioned encryption; v1 = APP_KEY/app cipher, v≥2 = AES-256-GCM from env) used for integration credentials and notification channel destinations.
- [x] `IntegrationCredentialService`: issue, request rotation, rotate (signer → draining 24 h, undelivered active → revoked), confirm (first use of the new key revokes draining keys), audit for each.
- [x] HMAC middleware accepts `draining` keys until `expires_at`, decrypts through the keyring, uses `now()` for the timestamp window.
- [x] `POST /integrations/{id}/rotate` (admin, idempotent; only `plugin_hmac`), signed `POST /ingest/credentials/rotate`, heartbeat `credential_rotation_requested`.
- [x] `security:reencrypt-secrets` command; `keyring` in `/health/ready`.
- [x] `.env.example`: keyring, draining and rate-limit variables.

## Verification

- [x] pint on changed files.
- [x] Full `php artisan test` (PHP 8.4): 245 tests passed (10 new in `CredentialRotationTest`).
- [x] No schema change in this step.

## Not Done In This Step

- Plugin-side rotation client (plugin not implemented yet).
- Stripe API/webhook credential rotation.
- A dedicated test that a Redis outage makes nonce checks fail closed.
