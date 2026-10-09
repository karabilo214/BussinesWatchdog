# Frontend

Customer dashboard (`/app/`): Vue 3 + TypeScript + Vite + Tailwind CSS 4, session auth via Sanctum (ADR 0016).

Planned surfaces: `/app` customer workspace (in progress), `/owner` platform owner, `/admin` platform staff, public pages per `spec/panels/PUBLIC-SUBSCRIPTION-FRONTEND.md`. The frontend uses backend authorization results and never duplicates financial, tenant isolation or entitlement rules.

## Setup

Node 24 LTS (`.nvmrc`). From the repository root:

```sh
make frontend-install   # npm ci
make frontend-dev       # Vite on http://localhost:5173/app/, proxies /api and /sanctum to http://127.0.0.1:8000
make frontend-check     # palette, vue-tsc, Vitest, production build
make frontend-smoke     # backend + Vite + Chromium end-to-end smoke
```

Run the backend with `php artisan serve --port=8000` (with `DB_HOST=127.0.0.1` and, without phpredis, `CACHE_STORE=database`). `BW_BACKEND_URL` overrides the proxy target.

## Conventions

- Colours: only the frozen palette (ADR 0015, `docs/design/palette.md`). Tailwind's default colours are disabled; use semantic tokens (`bg-primary`, `text-muted`, `bg-ok-soft text-ok`, `bg-unknown-soft text-unknown`, …). "Unknown" never renders green.
- Text: every user-facing string is a translation key in `src/i18n/locales/{ru,en,de}.json`; `tests/i18n.test.ts` keeps them in sync.
- API: `src/api/http.ts` (CSRF, `If-Match`, `Idempotency-Key`, request IDs); types in `src/api/types.ts` mirror `contracts/openapi.yaml`.
