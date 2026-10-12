# ADR 0031: One trial per store domain

Status: implemented (Step 100); owner decision 2026-10-12

## Context

ADR 0030 gave our own trial once per tenant (`tenants.trial_consumed_at`). One person can create several organisations and take a new trial each time. Owner 2026-10-11/12: also limit trials by store address; `www.` and the bare domain are one store; both checks apply together (organisation and domain).

## Decisions

1. **Two checks at trial start**: the organisation has never had a trial (unchanged) **and** none of its verified stores has a domain that already had a trial in another organisation. Otherwise 409 `trial_not_available` / `trial_store_already_used`.
2. **Domain**: the store URL host, lower-case, trailing dot removed, IDN as punycode, one leading `www.` removed (`www.shop.de` = `shop.de`). Port and path are ignored. Subdomains are different domains (`eu.shop.de` ≠ `shop.de`).
3. **Only verified stores claim a domain.** A claim needs proven control of the store (DNS, plugin challenge or a read-only REST key on the store's own origin), so nobody can burn the trial of a domain they do not own by typing its address. An unverified store gets no browser checks and no notifications.
4. **When a domain is claimed** (`trial_store_hosts`, unique host → first organisation wins, kept after the store or the trial is gone): at trial start for every verified store of the organisation, and whenever a store is verified in an organisation that has consumed its trial (during the trial or after it).
5. **During a trial a store whose domain already had a trial elsewhere cannot be verified**: the verification fails with `trial_store_already_used` (shown in the store's confirmation panel). After a purchase the same store can be verified. Adding a store or changing its address resets verification, so both go through this check.
6. The lookup is cross-organisation by design and answers only "another organisation holds this domain"; no other data from that organisation is read or shown.

## Not covered

- Different domains for the same shop (e.g. `shop.de` and `shop.at`) are different stores.
- An unverified store can still be added and connected during a trial; it gets money reconciliation, but no checks and no notifications.
