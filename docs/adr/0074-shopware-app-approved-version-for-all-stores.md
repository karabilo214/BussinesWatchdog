# ADR 0074: One approved Shopware App version for every store

Date: 2026-10-21

Status: accepted (owner's decision 2026-10-21)

## Context

Step 202 closed the Shopware App download in production behind two approvals: the fingerprint of the App version (`WATCHDOG_SHOPWARE_APP_APPROVED_SHA256`) and, in addition, the UUID of each store (`WATCHDOG_SHOPWARE_APP_APPROVED_STORE_IDS`), added only after the App and the store's checkout had been checked on a copy of that store. The backlog item on third-party themes (2026-10-21) repeated the idea: a wizard on a copy of the store before the App is issued to it.

The owner rejected both: merchants do not install an app on a copy first and then on the live shop, and fitting the App to each customer is the wrong model. The archive is already the same code for every store; only the registration address and the connection secret differ.

## Decision

1. **The owner approves a version, not a store.** The release gate keeps only the version fingerprint (App files, package builder, version, permissions, events). Once the owner has checked a version and set its fingerprint in the production `.env`, every customer can download and update the App of that version in the cabinet.
2. **No copy of the store.** There is no check on a copy before issuing the App and no per-store list; `WATCHDOG_SHOPWARE_APP_APPROVED_STORE_IDS` is removed.
3. **Theme compatibility is handled on the live store**: settings of the store (pages and elements of the checkout) and the safe browser check — never by a per-store App build. Where the App's script cannot observe a theme, the store shows «observation unavailable / not confirmed», not a broken checkout.
4. Shopware versions on which the App cannot be installed stay named in the cabinet at the App download step (6.6.10.25–6.6.10.29).

## Consequences

- One approval opens the App for all stores; a change of any App file or the package builder closes the download until the next approval.
- A store on an unusual theme can install the approved App before anyone has looked at its checkout; the observation then reports what it can or says it is not confirmed.

## Verification

`CheckoutObservationTest::test_shopware_app_archive_is_issued_to_every_store_once_the_exact_version_is_approved`: refused without approval, issued with the exact fingerprint, refused again with another one.
