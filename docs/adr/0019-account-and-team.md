# ADR 0019: Account Recovery, Email Confirmation and Team Management

Date: 2026-10-10

Status: implemented in Step 64; shown to the owner for review.

## Decisions

- **Password reset.** `POST /auth/password-reset/request` answers `202` the same way for known and unknown addresses (no account enumeration). Known active users get a one-time link valid 60 minutes; only a SHA-256 of the token is stored (`password_reset_tokens`). Completing it changes the password, rotates the remember token, ends every session of the user and marks the address confirmed (the link proved access to the mailbox). Wrong, unknown and expired tokens give the same `password_reset_invalid`. Limits: 3/hour per address and 20/hour per IP for requests, 10/minute per IP for completion.
- **Email confirmation.** Registration mails a signed, relative-signature link valid 24 hours: `GET /api/v1/auth/email-verification/{user}/{hash}`. The hash binds the link to the current address. It works without a session (opened on another device) and redirects to `/app/overview?email_verified=1|0`. A new link once a minute; an unconfirmed address does not block using the dashboard (the banner asks to confirm).
- **Password change** requires the current password and ends every other session; the current one stays signed in with a new session id.
- **Team roles.** Owners and admins manage the team. The owner may give admin, operator or viewer; an admin only manages operators and viewers and only gives those roles. Nobody changes their own role. The owner role is never assigned here (ownership transfer is a separate future feature with re-authentication), so a tenant always keeps its owner. Any non-owner may leave; the owner cannot. Removal takes effect on the next request (membership is checked per request). Every change is audited (`membership.role_changed`, `membership.removed`, `membership.left`, `invitation.*`).
- **Invitations.** 7-day link mailed to the address; only the token hash is stored. A new invitation to the same address revokes the pending one. Inviting an existing member answers `409 already_member`. Accepting requires that the signed-in user's address is the invited one; accepting confirms that address (the token was delivered to it). A person without an account registers through the invitation (`POST /auth/register` with `invitation_token`): the account joins the inviting team and no new tenant is created. The public lookup shows the team name, role, address and whether an account already exists — only to whoever holds the token.
- **Missing team access** (no active team in the session, or the membership was removed) answers `403 tenant_forbidden` instead of a server error.

## Not done

MFA, ownership transfer, creating/switching between several teams in the dashboard, session list per device.
