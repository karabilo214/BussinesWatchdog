# ADR 0049: Replies and internal notes on contact messages

Status: accepted for now (owner's backlog items 2026-10-13: a «Reply» button — template, subject, text, sent to the person, kept with the message; internal notes as a list with date and staff member). Details chosen by the implementer while the owner was away; to be confirmed by the owner.

## Decisions (replies, Step 128)

1. **Templates**: answer, more_details, resolved — subject and starting text in the language of the message (`lang/*/site.php`, `site.contact.reply.templates`); staff edit both. The letter always adds a greeting by name, the team signature and the original message with its date (UTC). Plain text, like every other letter.
2. **Who**: `contacts.manage` (owner, support). At most 60 replies an hour per staff member.
3. **Stored**: `contact_request_replies` (template, subject, text, staff member, sent at) — deleted together with the message by the existing retention (365 days after closing). Only a reply the mail server accepted is stored (502 `reply_not_sent` otherwise). A new message becomes "in progress"; a closed one stays closed.
4. **Audit**: `site.contact.replied` with the reply id, template and a content hash, no text.
