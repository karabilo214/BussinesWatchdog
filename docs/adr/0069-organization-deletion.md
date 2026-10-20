# ADR 0069: Deleting an organization (tenant) and its data

Status: decisions 1–4 by the owner on 2026-10-20; implemented in Steps 203 (backend) and 204 (cabinet, owner panel, emails).

## Context

TZ §33 sets the order: the owner confirms in an authenticated UI → request record → processing suspended → credentials revoked → objects deleted → tenant domain data purged → users that belong to no other tenant deleted or anonymized → only a minimal lawful record kept; restoring a backup applies a deletion journal before data is shown again. CLIENT-PANELS §6: owner step-up, an admin gets 403; stopping monitoring, cancelling the subscription and deleting the organization are separate actions, and the paid contract must be cancelled before the purge. ACC-42 and EXT-26 were not done. The published Privacy Policy (ADR 0065) promises account data deleted within 30 days after closing or on request, and backups kept up to 12 months.

## Decisions (owner, 2026-10-20)

1. **30 days before the purge.**
   - Right after the request the organization is `deleting`: no ingestion, webhooks, syncs, reconciliation, browser checks or notifications (the same gates as the full store pause, ADR 0037), and members see only the deletion notice.
   - For 30 days the owner (or the platform owner) can cancel. Cancelling restores the previous status, and connections resume from where they stopped.
   - After 30 days the purge starts; from then on it cannot be undone.
2. **A paid Paddle subscription is cancelled immediately when the request is made** (`effective_from: immediately`), without a refund for the rest of the period (Refund Policy: renewals are not refunded; a 14-day guarantee refund is a separate request). If Paddle does not confirm, the request is refused and nothing changes. Cancelling the deletion does not bring the subscription back.
3. **What is kept after the purge:**
   - billing records without people: Paddle customer reference, subscription contracts, transactions, refunds; amounts, dates, Paddle IDs, invoice numbers — for accounting and disputes, 10 years (German retention for accounting records; to be confirmed with the lawyer; automatic removal after 10 years is a later task);
   - the acceptances of the terms and policies bound to those contracts, with an anonymized user;
   - the trial domains of the organization's stores, only as keyed hashes (HMAC with the application key), so the same store cannot start a second trial (ADR 0031);
   - the deletion journal: organization ID, request ID, who started it (owner or platform), dates; no names;
   - the platform audit, 2 years as already published (ADR 0053);
   - the `tenants` row itself as an anonymized tombstone (`deleted`, name «Deleted organization»), because the kept records point to it.
4. **Who can start it:**
   - the organization owner in the cabinet: current password, MFA code when MFA is on, typing the organization name;
   - the platform owner (not other staff) in the owner panel, on the customer's written request, with a reason and the authenticator code. The organization owners get an email in both cases.

## Design

- `data_deletion_requests` (TZ schema plus `requested_via`, `reason`, `previous_status`, `purge_after`, `cancelled_at/by`, `lease_until`, `attempts`): one open request per tenant.
- **Statuses:** `requested` (the 30 days) → `revoking` → `deleting_objects` → `purging` → `completed`; also `cancelled` and `failed`. `cursor` records the finished stages and tables, so every run continues where the last one stopped (resumable, idempotent).
- **`php artisan privacy:process-deletions`** (scheduler, every 5 minutes, one server):
  - `revoking`: integrations become `revoked`, credentials, pairing codes and channel destinations are deleted;
  - `deleting_objects`: screenshot and export files;
  - `purging`: every tenant table in foreign-key order, in chunks of 500;
  - then members who belong to no other organization and are not platform staff are anonymized (email `deleted-<id>@deleted.invalid`, name, password, MFA, sessions, tokens);
  - trial hosts are hashed, the tombstone is written, and the journal entry is added.
- **Coverage guard:** a test lists every table with `tenant_id` and fails when one is neither purged nor explicitly kept, so new tables cannot be forgotten.
- **Backups:**
  - every nightly backup also writes the deletion journal (`php artisan privacy:deletion-journal`), encrypted like the dump;
  - after a restore, `php artisan privacy:replay-deletions <journal>` purges again every organization of the newest journal before the service is opened (`docs/ops/backups.md`);
  - a deletion made after the last backup is not in it (RPO one day).

## Consequences

- ACC-42 and EXT-26 close once Steps 203–204 are done.
- The Privacy Policy should mention the kept billing records and trial-domain hashes (owner, in the page editor).
