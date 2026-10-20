# Implementation Step 56 Checklist: Dashboard Scaffold

Stage: P0 minimal dashboard (spec §2, §36 navigation/i18n/accessibility; ADR 0015, 0016)

## Scope

- [x] Vue 3 + TypeScript + Vite + Tailwind 4 project with pinned versions, Node 24 LTS, `.npmrc`.
- [x] Frozen palette wired in (`palette.css` via `main.css`), self-hosted IBM Plex (Latin + Cyrillic).
- [x] API client: Sanctum CSRF cookie, session cookies, `If-Match`, `Idempotency-Key`, `X-Request-ID`, typed `ApiError`; 401 → login.
- [x] i18n ru/en/de with persisted choice and `<html lang>`.
- [x] Router under `/app/` with auth guard and redirect back after login.
- [x] App layout (header, navigation, language, sign out), login page, overview with store coverage cards (loading, error with request ID, empty states).
- [x] Backend: `localhost:5173` is in `SANCTUM_STATEFUL_DOMAINS` (`.env.example` already had it; the local `.env` was updated).

## Verification

- [x] `make frontend-check`: palette ok, vue-tsc clean, Vitest 12/12 (contract states ↔ colours/labels, translations, API client, store card), build ok; built CSS contains only the seven palette scales.
- [x] `make frontend-smoke`: 8/8 steps in Chromium against the real backend (screenshots inspected, desktop ru and mobile de).
- [x] Backend suites unchanged and green.
