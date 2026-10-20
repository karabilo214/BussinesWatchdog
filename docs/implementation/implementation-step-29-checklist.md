# Implementation Step 29 Checklist: Integration Lifecycle And Audit

Stage: D3 integration management foundation
Spec references: `spec/Business-Watchdog-TZ.md` sections 8, 25, 26, 27, 36.
Acceptance references: `ACC-02`, `ACC-04`, `ACC-05`.

This step adds tenant-scoped integration management surfaces and a minimal sanitized audit trail before allocation and reconciliation work.

## Scope

- [x] Add `audit_log` bootstrap table.
- [x] Add `AuditLog` model with integration lifecycle actions.
- [x] Add integration list endpoint.
- [x] Add integration detail endpoint.
- [x] Add integration revoke endpoint.
- [x] Revoke all non-revoked integration credentials with the integration.
- [x] Return credential summaries without ciphertext, fingerprint, or secret material.
- [x] Write audit log entries for pairing exchange and integration revoke.
- [x] Enforce tenant scoping and tenant role checks on integration endpoints.

## Verification

- [x] Run PHP syntax checks for changed integration/audit files.
- [x] Run focused integration API tests.
- [x] Run focused pairing API tests.
- [x] Run full `php artisan test`; 92 tests passed with 307 assertions.

## Not Done In This Step

- Credential rotation/draining endpoint.
- Stripe integration UI/API.
- Audit log list/export endpoint.
- Staff/platform audit separation.
- Manual support grants and impersonation controls.
