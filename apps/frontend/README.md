# Frontend

Vue 3 + TypeScript + Tailwind CSS frontend (scaffold follows).

Colours: the corporate palette is frozen (ADR 0015) — `design/palette.json`, `src/styles/palette.css`, rules in `docs/design/palette.md`; check with `make frontend-palette-check`. Tailwind's default colours are disabled; use the semantic tokens (`bg-primary`, `text-muted`, `bg-ok-soft text-ok`, `bg-unknown-soft text-unknown`, …).

Planned surfaces:

- `/app` — customer tenant workspace.
- `/owner` — platform owner control surface.
- `/admin` — platform staff operations.
- Public pages and billing flow per `spec/panels/PUBLIC-SUBSCRIPTION-FRONTEND.md`.

Frontend must use backend authorization results and must not duplicate financial, tenant isolation, or entitlement rules.

