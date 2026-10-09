# ADR 0016: Dashboard Frontend Scaffold

Date: 2026-10-09

Status: implemented in Step 56.

## Decisions

- **Stack** (spec §2: Vue 3 + TypeScript + Vite, SPA with Sanctum): Vue 3.5.43, vue-router 4.6.4, vue-i18n 11.4.12, Vite 8.3.1, Tailwind CSS 4.3.3 (`@tailwindcss/vite`), TypeScript 5.9.3 with vue-tsc 3.3.11, Vitest 5.0.1 + @vue/test-utils + jsdom. All pinned exactly; every version was at least two weeks old when chosen. Deliberately not the newest majors: TypeScript 7 (new Go compiler, untested with vue-tsc) and vue-router 5 (pulls Pinia and other peers we do not use).
- **Node 24 LTS** (`apps/frontend/.nvmrc` = 24.21.0, engines `^22.22.2 || >=24.15.0`); the machine's default Node 23 is an odd, non-LTS line that jsdom's dependencies reject. `apps/frontend/.npmrc` sets `legacy-peer-deps=true`: every peer is declared explicitly, and npm crashes resolving vitest's optional browser-runner peers when optional dependencies are forced (a global `include=optional`).
- **Session, not tokens.** Same-origin SPA under `/app/` (matches incident links in e-mails), Sanctum stateful session with the `XSRF-TOKEN` cookie (fetched from `/sanctum/csrf-cookie` before the first mutation and echoed as `X-XSRF-TOKEN`); nothing is stored in localStorage except the chosen language. In development Vite proxies `/api` and `/sanctum` to Laravel; `localhost:5173` is in `SANCTUM_STATEFUL_DOMAINS`. An expired session (401) returns the user to the login page.
- **API client** sends `If-Match`, `Idempotency-Key`, keeps `X-Request-ID` for error messages, and turns problem responses into `ApiError(status, code, fieldErrors, requestId)`; the UI translates stable codes, never shows server text.
- **i18n**: ru/en/de from day one; a test fails when a key, a placeholder or a translation is missing in any language. The UI shows only implemented pages (no placeholder screens).
- **Fonts self-hosted** (`@fontsource/ibm-plex-sans`/`-mono`, incl. Cyrillic): no request to Google Fonts, which matters under German/EU privacy rules.
- **Colours** only through the frozen palette's semantic tokens (ADR 0015). A test reads `StoreCoverage` enums from `contracts/openapi.yaml`: every state needs a colour and a label in all languages, and only the healthy state of each part may be green.
- **Verification**: `make frontend-check` (palette, types, unit tests, build) and `make frontend-smoke` (real backend + Vite + Chromium from the worker image: redirect to login, wrong password, login, coverage from the API, reload, language switch, mobile width, sign out, no console errors).

## Not decided yet

Production serving of `/app/` (nginx serving `dist/` next to the API is the intended setup) and the remaining dashboard pages.
