# ADR 0028: Billing provider for the Business Watchdog subscription

Status: direction accepted by the owner (2026-10-11); provider account and live verification pending

## Context

The spec defines a provider-neutral billing adapter (spec/panels/ADMIN-BACKEND.md §3) and leaves the provider to the owner. Facts from the owner: payouts first to a private person in Ukraine, later a Ukrainian sole proprietorship (ФОП); a partner may open a German company later; the service runs on a VPS in Germany; customers are both businesses and consumers in Germany/EU and beyond. The owner does not want to handle EU VAT, invoices and reverse charge himself.

## Decision

- **A Merchant of Record, Paddle first.** Paddle sells the subscription as the merchant: it charges and remits VAT/sales tax, issues the invoices (accepted by German B2B buyers), handles reverse charge for EU VAT IDs, refunds and disputes, and pays out in USD/EUR. Checked 2026-10-11 from public sources: Ukraine is not a restricted seller country (only the occupied regions are); Paddle's API knows the seller type "individual / sole trader / self-employed"; payouts in USD, EUR, GBP, AUD, CAD. Whether a Ukrainian private person passes Paddle's verification is confirmed only at live onboarding — the owner's step.
- **Not PayPal Subscriptions directly**: cheaper, but VAT registration (OSS), invoices with German requirements, reverse charge and plan changes would be ours. **Lemon Squeezy** is the fallback MoR; its payouts to Ukraine are not confirmed. **Stripe** waits for a company.
- The adapter stays behind the spec's interface; a second provider (for example PayPal or Stripe for the German company) can be added without changing tariffs, entitlements or panels.
- Development starts in Paddle's sandbox, which needs no verification.

## Consequences and open items (owner, not code)

- Tax on the payouts in Ukraine (private person vs ФОП) — accountant.
- Legal pages for the German market (Impressum, privacy policy, terms, a data processing agreement for customers' order data) name the operator; a German company later simplifies this — lawyer.
- When the German company exists, the Paddle seller entity can move to it.
