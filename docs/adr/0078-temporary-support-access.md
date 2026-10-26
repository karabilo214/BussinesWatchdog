# ADR 0078: Temporary support access to one customer store

Date: 2026-10-23

Status: accepted (owner's decisions 2026-10-23)

## Context

The platform already has separate staff accounts, capabilities and audited actions. Detailed customer store endpoints, however, used `tenants.manage` alone. Even a platform owner could open orders, findings, checks and screenshots without the organisation owner's permission. This contradicted `OWNER-PANEL.md` §4, `ADMIN-PANEL.md` §3 and EXT-05/06.

## Decisions

1. A platform employee with `tenants.support`, or the organisation owner, may request access for one store. A customer request names an active employee chosen from a list of support employees by name (amended 2026-10-25, Step 244: the owner decided staff e-mail addresses are not shown to customers; the list holds only active staff allowed `tenants.support`). Only `tenant_owner` approves, denies and revokes. The platform owner has no exception.
2. Every request requires a support case reference and a written reason. Duration is 24 hours by default and at most 72 hours. The clock starts on approval. A pending request grants nothing. The approved scopes cannot be enlarged; a new request and approval are required.
   A new request sends an e-mail to every organisation owner with a link to the decision screen. Repeating the same pending request does not send another e-mail.
   Amendment 2026-10-23: the service assigns a single global sequential reference (`SUP-000001`, `SUP-000002`, …) when the request is created. Neither staff nor customers supply or edit it. The written reason remains mandatory. A retry of an identical pending request retains its reference and does not consume another number.
   Amendment 2026-10-25 (Step 251, owner request): the requesting employee may cancel their own pending request (new state `cancelled`; the owner can no longer approve it) and «send again» any of their requests — a pending one re-sends the owner e-mail, a denied, revoked, expired or cancelled one becomes a new pending request with the same store, scopes, duration and reason and a new reference. Both are recorded in the platform and customer audit.
   The employee may also hide a finished request (denied, revoked, expired, cancelled) from their own history (Step 252). Nothing is deleted: the request, the customer's history and both audits keep it; a pending or still active request must be cancelled or revoked first.
3. Scopes are `read_diagnostics` (incidents, checks, orders, related payments and findings), `read_artifacts` (redacted screenshots and materials) and `request_recheck` (existing safe browser scenario only). Step 269 adds `edit_store`, limited to the display name of one store. Detailed reads and this mutation need both the staff capability and an active grant for the exact tenant and store. No export or customer impersonation is granted. Provider secrets never enter the support response.
4. Revocation and expiry are checked on each API request. Staff artifact links point back to an authenticated, grant-checked application route rather than to object storage, so a link cannot outlive its permission. Staff and tenant audit journals record decisions, detailed opens, downloads and rechecks with IDs and times, not order contents or secrets. The customer panel explains that already viewed information cannot be taken back.
5. Acknowledging or resolving a customer incident uses the separate assignable capability `tenants.incident_actions`, a reason, platform and customer audit, and e-mail to the organisation owner. It does not grant diagnostic read access. Creating or deleting a store remains a separate administrative action with its existing capability, step-up and audit checks.
6. The existing platform tenant card remains a role-limited commercial and operational summary. Detailed store routes cannot be reached through `tenants.manage`, direct UUIDs or artifact URLs without the grant. Rechecks still obey their existing safety and rate limits.
7. For `edit_store`, the platform API accepts the old and new name plus a written reason. It locks the store and the employee's grant in one transaction, checks that approval is still active, and rejects a stale old name with 409. The action changes no URL, platform, currency, connection or monitoring setting. Platform audit and the customer's support-access history show the name before and after, the employee and the reason. The platform owner has no bypass. The edit screen requests active grants separately from the 100-row history limit. No schema migration is needed because `scopes` is a JSON list.

## Consequences

- The owner panel has a support-access tab and the customer settings have a grant page. An open staff page drops loaded details when it detects revocation or expiry; the server checks every subsequent request independently.
- The grant is deleted with its store or organisation. Platform audit metadata remains under the existing retention policy.
- There is no emergency bypass or broader delegated customer account in this release. A future break-glass procedure needs a separate decision.
