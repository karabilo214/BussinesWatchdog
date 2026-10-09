# Frontend

npm workspace with three applications (ADR 0017) on Vue 3 + TypeScript + Vite + Tailwind CSS 4 (ADR 0016):

| App | Path | Dev port | API |
| --- | --- | --- | --- |
| `customer/` | `/app/` customer workspace, Sanctum session | 5173 | `/api/v1` |
| `control/` | `/owner`, `/admin` platform control zone (placeholders) | 5174 | `/platform-api/v1` |
| `public/` | public site, prerendered `/`, `/ru/`, `/en/`, `/de/` | 5175 | none |

Shared packages: `packages/design` (frozen palette, base styles, fonts), `packages/ui` (status badge, tones), `packages/i18n` (locales, language switch, translation parity check), `packages/api-client` (CSRF/session HTTP client).

The frontends use backend authorization results and never duplicate financial, tenant isolation or entitlement rules.

## Setup

Node 24 LTS (`.nvmrc`). From the repository root:

```sh
make frontend-install       # npm ci for the whole workspace
make frontend-dev           # customer on http://localhost:5173/app/, proxies /api and /sanctum to http://127.0.0.1:8000
make frontend-dev-control   # control on http://localhost:5174/owner
make frontend-dev-public    # public site on http://localhost:5175/
make frontend-check         # palette, then vue-tsc, Vitest and build of every app (public: prerender check)
make frontend-smoke         # backend + customer Vite + Chromium end-to-end smoke
```

Run the backend with `php artisan serve --port=8000` (with `DB_HOST=127.0.0.1` and, without phpredis, `CACHE_STORE=database`). `BW_BACKEND_URL` overrides the proxy target.

Root scripts call nested npm through `node "$npm_execpath"`, never a bare `npm`: `npm run` puts ancestor `node_modules/.bin` directories on `PATH`, and an old npm there recursed without limit.

## Conventions

- Colours: only the frozen palette (ADR 0015, `docs/design/palette.md`, `packages/design`). Tailwind's default colours are disabled; use semantic tokens (`bg-primary`, `text-muted`, `bg-ok-soft text-ok`, `bg-unknown-soft text-unknown`, …). "Unknown" never renders green.
- Text: every user-facing string is a translation key in `<app>/src/i18n/locales/{ru,en,de}.json`; each app's tests keep the languages in sync (`@bw/i18n/testing`).
- API: `@bw/api-client` (CSRF, `If-Match`, `Idempotency-Key`, request IDs); customer types in `customer/src/api/types.ts` mirror `contracts/openapi.yaml`.
