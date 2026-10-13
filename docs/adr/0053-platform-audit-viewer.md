# ADR 0053: Platform audit viewer («Аудит»)

Status: accepted for now (owner's backlog item 2026-10-13: the «Аудит» section is not ready). Follows spec/panels/OWNER-PANEL.md §7 (platform audit); details chosen by the implementer while the owner was away; to be confirmed by the owner.

## Decisions

1. **Who**: `audit.read` — platform owner and platform admin (as already assigned); support and billing do not see it.
2. **What**: the platform audit (not the organisations' own `audit_log`), newest first, 50 per page with a cursor; filters by actor (staff member or system), exact action (chosen from the actions present), organisation, result and UTC dates; each entry shows actor, time, organisation link, reason and on demand the target, request id and sanitized changes. Labels for known actions in ru/en/de; unknown actions show their code.
3. **Export**: synchronous CSV of the same filters, at most 10 000 newest rows, `no-store`; cells starting with `=`, `+`, `-`, `@` are prefixed with a quote against spreadsheet formula injection; every export is itself audited (`audit.exported` with the filters). The spec's asynchronous export with a short-lived private link is left for when the audit grows beyond the limit.
4. **Retention**: unchanged — nothing is deleted from the platform audit by this step.
