# ADR 0065: Legal documents of the site (terms, privacy, subscription and refunds, legal notice)

Status: texts prepared as drafts (Step 193); points 1–4 decided by the owner on 2026-10-19; the texts are not reviewed by a lawyer

## Context

Paddle approves the domain only when the live site shows the product, prices, Terms and Conditions with the seller's legal name and Paddle as Merchant of Record, a Refund Policy and a Privacy Policy, all reachable from the navigation. The site already supports versioned legal pages and consent at sign-up and purchase (ADR 0042); the texts were missing. The owner asked for all three languages and for the German requirements to be covered; the texts are edited in the owner panel (Site → Pages).

## Decisions

1. **Seller** (owner): a private individual; the Terms, the Privacy Policy and the Legal Notice name them with the legal name and postal address given to `site:legal-drafts`.
2. **Refunds** (owner): 14-day money-back on the first payment of a subscription, without reasons; renewals are not refunded unless the law requires it or the Service was unavailable for a substantial part of the period for reasons on our side; Paddle processes refunds; a guarantee refund also cancels the subscription.
3. **Customers and law** (owner): business customers only (§ 14 BGB in the German text); law of Ukraine without the CISG, mandatory law of the customer's country unaffected; courts of Ukraine at the seller's residence after an attempt to settle in writing; the English text prevails.
4. **Prices and plans** (owner): the ADR 0041 grid for now (Start 19 €, Business 39 €, Growth 79 €, Enterprise individual), created by the owner in `/owner/plans`; the site switches to Paddle live once the pages are published and the owner provides the live keys.
5. **Assumptions in the texts, matching the code** — to be kept true when the code changes:
   - a running subscription keeps its price (plan versions do not move current customers, ADR 0035); if a running price ever has to change, 30 days' email notice before the renewal;
   - upgrade immediately with proration, downgrade at the next period, stores over the limit paused with possible deviations (ADR 0037, Step 168);
   - failed renewal: paid features restricted after 7 days (`past_due_grace_days`);
   - trial without payment details, once per store (ADR 0030, 0031);
   - retention: contact messages 1 year after closing, public check screenshots 5 minutes and records 90 days, check screenshots 30 days, sessions 2 hours, platform audit 2 years, backups up to 12 months (Steps 188–190), account data deleted within 30 days after closing or on request;
   - the public site sets no cookies except the pages with a form (session and XSRF cookies); no analytics or third-party fonts and scripts; the payment step loads Paddle.js;
   - processors: Contabo GmbH (servers, backups); Paddle as its own controller; Telegram only when connected;
   - the Service collects no names, addresses or emails of the stores' customers;
   - container logs rotate (`json-file`, 20 MB × 5).
6. **German requirements**: Impressum per § 5 DDG and § 18 (2) MStV (name, postal address, email, contact form); Datenschutzerklärung with legal bases (Art. 6 DSGVO), rights including the complaint to a supervisory authority (Art. 77), § 25 (2) TDDDG for the necessary cookies (no consent banner); a statement that no consumer arbitration takes place (§ 36 VSBG). The EU online dispute platform was closed in July 2025 and is not linked.
7. **Delivery**: `php artisan site:legal-drafts <owner email> --name=… --address=…` creates the footer pages `terms` (service_terms), `privacy` (privacy_notice), `refund-policy` (recurring_payment), `imprint` and saves the texts (`database/data/legal/*.php`) as drafts in en/de/ru; it never publishes and leaves published versions in place. Once published, sign-up and purchases ask for consent to the new versions (ADR 0042).

## Open (owner)

- Review by a lawyer before selling to consumers or at scale.
- A non-EU controller offering a service to people in the EU may need an EU representative (Art. 27 GDPR) unless the processing is occasional and low-risk; to be decided with the lawyer.
- Account deletion on request is a manual operation today (no self-service deletion yet).
