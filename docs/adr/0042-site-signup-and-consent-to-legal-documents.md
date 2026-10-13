# ADR 0042: Sign-up on the site and consent to versioned legal documents

Status: implemented (Step 118); points 6–8 accepted by the owner on 2026-10-13

## Context

spec/panels/PUBLIC-SUBSCRIPTION-FRONTEND.md §4–§5 and §8: sign-up with terms acceptance bound to a version, privacy policy link, consent recorded with timestamp and purpose (not secrets or IP), purchase requests carry the accepted policy versions; legal published versions are immutable. The owner asked (2026-10-13) for sign-up on the site and consent to the terms, the terms being pages made in the owner panel, so a page needs a flag that says exactly which page is which document.

## Decisions

1. **Document flag**: a page of the site has `policy_type` — `service_terms` (terms of service), `privacy_notice` (privacy notice) or `recurring_payment` (subscription and auto-renewal terms); set in the page settings of the owner panel, only on ordinary pages, one active page per type (409 `policy_taken`), cleared when the page is archived; the address `signup` cannot be used by a page. The published version of the page in a language is the version of the document (immutable, ADR 0039); a new publication is a new version.
2. **Which documents for what**: sign-up — terms of service and privacy notice; trial, purchase and plan change — terms of service and subscription terms. A document is required only when its page is marked and published; the version shown is the person's language, else English, else any published language.
3. **Consent**: the form sends the ids of the versions it showed; the server accepts the action only when every required document is accepted in a currently published version (any language), otherwise nothing happens and 422 `policy_acceptance_required` returns the current documents (the site form says the documents changed and asks again). Consent is stored in `policy_acceptances` (user, organisation, type, page version, language, version number, content hash, purpose, the trial/checkout/plan change it belongs to, time) — insert only, no IP.
4. **Sign-up on the site**: `/{lang}/signup`, rendered on the server and usable without JavaScript: name, organisation, email, password (≥ 12, twice), time zone (default by language), one consent checkbox with links to the documents, hidden honeypot; an existing email is refused with a link to sign in and password recovery; on success the account, organisation and owner membership are created with the consent in one transaction, the person is signed in (the same session as the customer account), the email verification is sent and the person lands on `/app/settings/billing` (with `?plan=` from the pricing page). A signed-in person opening the page goes straight to the account. The pricing page's «Choose plan», the home page and the header link to it.
5. **Cabinet**: the billing page shows the consent checkbox with links whenever documents exist; trial, purchase and plan-change confirmation stay disabled until it is ticked; a refusal because the documents changed reloads them and clears the tick. `POST /api/v1/auth/register` (non-invitation) requires the same consent as the site form.
6. **Rate limit** (accepted): 3 real sign-ups an hour per IP (`WATCHDOG_RATE_SIGNUP_PER_HOUR`) on the site form; submissions that fail field validation do not count; the limit is shown as a form error.
7. **Invitations** (accepted): a person joining a team by invitation does not accept the documents (the organisation owner already did); purchases they cannot make anyway.
8. **No documents marked** (accepted): sign-up and purchases work without a consent step until the owner marks and publishes the pages; before selling the owner must do it.

Also fixed: a guest calling the API without `Accept: application/json` got a 500 (`Route [login] not defined`); the API now answers 401 and other pages send guests to `/app/login`.
