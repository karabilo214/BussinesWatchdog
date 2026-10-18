# ADR 0053: Platform audit viewer («Аудит»)

Status: accepted for now (owner's backlog item 2026-10-13: the «Аудит» section is not ready). Follows spec/panels/OWNER-PANEL.md §7 (platform audit); details chosen by the implementer while the owner was away; reviewed by the owner on 2026-10-16.

## Decisions

1. **Who**: `audit.read` — platform owner and platform admin (as already assigned); support and billing do not see it.
2. **What**: the platform audit (not the organisations' own `audit_log`), newest first, 50 per page with a cursor; filters by actor (staff member or system), exact action (chosen from the actions present), organisation, result and UTC dates; each entry shows actor, time, organisation link, reason and on demand the target, request id and sanitized changes. Labels for known actions in ru/en/de; unknown actions show their code.
3. **Export**: synchronous CSV of the same filters, at most 10 000 newest rows, `no-store`; cells starting with `=`, `+`, `-`, `@` are prefixed with a quote against spreadsheet formula injection; every export is itself audited (`audit.exported` with the filters). The spec's asynchronous export with a short-lived private link is left for when the audit grows beyond the limit.
4. **Retention**: unchanged — nothing is deleted from the platform audit by this step.

## Owner decision (2026-10-16)

- Points 1–3 are confirmed; the filtered CSV export with at most 10 000 rows is enough.
- Retention changes: platform audit entries are deleted 2 years after they were written (they now include e-mail addresses and texts, ADR 0047/0049/0052).
- Implemented in Step 181: `platform:audit-purge` (daily 03:50 UTC) deletes platform audit entries older than `WATCHDOG_PLATFORM_AUDIT_RETENTION_DAYS` (730); «Настройки» shows the period. The organisation audit is not affected.
