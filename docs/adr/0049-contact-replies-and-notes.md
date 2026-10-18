# ADR 0049: Replies and internal notes on contact messages

Status: accepted for now (owner's backlog items 2026-10-13: a «Reply» button — template, subject, text, sent to the person, kept with the message; internal notes as a list with date and staff member). Details chosen by the implementer while the owner was away; reviewed by the owner on 2026-10-16 with changes.

## Decisions (replies, Step 128)

1. **Templates**: answer, more_details, resolved — subject and starting text in the language of the message (`lang/*/site.php`, `site.contact.reply.templates`); staff edit both. The letter always adds a greeting by name, the team signature and the original message with its date (UTC). Plain text, like every other letter.
2. **Who**: `contacts.manage` (owner, support). At most 60 replies an hour per staff member.
3. **Stored**: `contact_request_replies` (template, subject, text, staff member, sent at) — deleted together with the message by the existing retention (365 days after closing). Only a reply the mail server accepted is stored (502 `reply_not_sent` otherwise). A new message becomes "in progress"; a closed one stays closed.
4. **Audit**: `site.contact.replied` with the reply id, template and a content hash, no text.

## Decisions (internal notes, Step 129)

5. **Notes are a list**: `contact_request_notes` (text up to 2000 characters, staff member, time); a note is never edited or deleted on its own, only together with the message by retention. The single `contact_requests.note` field is gone: the migration turns each existing note into the first entry (author = the last handler, time = the last change) and drops the column.
6. **Who**: adding — `contacts.manage` (owner, support); reading — `contacts.read`. Audit `site.contact.noted` without the text. Changing the status is a separate action (`PATCH` now only takes `status`).

## Owner decision (2026-10-16)

- Reply templates become editable in the owner panel with a "save template" action next to "send", as for customer reminders (ADR 0047).
- Retention of 365 days after closing is confirmed.
- The author may edit their own note; the audit records the text before and after the change.

## Implementation of the owner decision (Step 178)

- Reply templates per code and language are stored in `contact_reply_templates`; until saved, the built-in text from `lang/*/site.php`. The reply form has "Save as template (DE)" next to "Send"; templates saved by staff are marked "own". Audit `site.contact.template_saved` with the text before and after.
- The author edits their own note (`PATCH …/notes/{note}`, 403 `note_not_yours` otherwise); the note shows "edited at"; audit `site.contact.note_edited` with the text before and after.

