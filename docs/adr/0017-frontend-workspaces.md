# ADR 0017: Three Frontends in One npm Workspace

Date: 2026-10-09

Status: implemented in Step 57.

## Context

`spec/panels/*` describe three separate surfaces: the customer workspace `/app` (`/api/v1`), the platform control zone `/owner` + `/admin` on its own origin `control.<service-domain>` with `/platform-api/v1` and its own host-only session cookie (OWNER-PANEL, ADMIN-PANEL), and a public site in ru/en/de that must be cacheable and indexable (PUBLIC-SUBSCRIPTION-FRONTEND). Step 56 built only `/app` as a single Vite project.

## Decisions

- `apps/frontend` is an npm workspace with three applications and shared packages:
  - `customer/` — `/app/` (port 5173, proxies `/api`, `/sanctum`); the former Step 56 project, unchanged in behaviour.
  - `control/` — `/owner` and `/admin` (port 5174, proxies `/platform-api`, `/sanctum`). Separate build so it can be served from the control origin with its own cookie; for now only placeholder sections, until the platform API exists.
  - `public/` — public site prerendered with `vite-ssg` 28.3.0 into `/`, `/ru/`, `/en/`, `/de/` (port 5175); every language page has its own `<html lang>`, title and description and is readable without JavaScript (`scripts/check-prerender.mjs`). No private API data is used in it.
  - `packages/design` (frozen palette, base styles, fonts — ADR 0015 paths moved here), `packages/ui` (`StatusBadge`, tones), `packages/i18n` (locales, shared strings, `LocaleSwitch`, translation-parity helper for tests), `packages/api-client` (CSRF/session HTTP client). Packages are consumed as TypeScript source, no separate build.
- One lockfile, one set of pinned versions (ADR 0016); each application has its own `check` (vue-tsc, Vitest, build), the root `npm run check` runs the palette check and all of them.
- Nested npm calls in root scripts go through `node "$npm_execpath"`, never a bare `npm`. `npm run` puts every ancestor `node_modules/.bin` on `PATH`; on the development machine an old npm 5 sits in `~/Sites/node_modules/.bin`, does not understand `-w` and re-ran the same root script, forking without limit until the machine froze.

## Not decided yet

Production hosting of the three builds (domains, nginx), and whether the public site and `/app` share an origin (allowed by the spec).
