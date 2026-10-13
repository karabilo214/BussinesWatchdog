# ADR 0047: Reminders to customers from the owner panel

Status: accepted for now (owner's backlog item 2026-10-13: "Напомнить о…" in the customer actions — subscription, payment, other — an email from a template, sending audited). Details chosen by the implementer while the owner was away; to be confirmed by the owner.

## Decisions

1. **Who receives**: the active owners of the organisation (role owner, account not disabled), each in their own language (ru/en/de, otherwise en). Admins, operators and viewers are not written to.
2. **Templates**: subscription and payment are fixed texts in `lang/*/account.php` (`reminder_subscription`, `reminder_payment`) with a link to the billing page of the account; they do not state amounts, dates or that a payment failed — they ask to check and say "ignore if all is in order", so a reminder can never claim something untrue about money. "Other" is a subject (3–150) and a plain-text message (10–5000) written by the staff member, with a greeting and the account link added.
3. **Flow**: the panel first shows the recipients and the letter per language (nothing sent), then sends. No authenticator code: sending an email does not change access or money.
4. **Who may send**: `tenants.manage` (platform owner), like the other customer actions.
5. **Repeats**: the same topic for the same organisation at most once in 10 minutes (409 `reminder_recently_sent`) to stop double clicks; the preview shows when it was last sent.
6. **Audit**: `tenant.reminder_sent` with the topic, recipient user ids and a hash of each language's letter — no email addresses or texts in the audit. The letter itself goes through the normal mailer (no copy is stored).
