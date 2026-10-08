# Applications

Application code lives here after D1 bootstrap:

- `backend/` — Laravel modular monolith.
- `frontend/` — Vue 3 customer and platform UI entrypoints.
- `browser-worker/` — Node.js + Playwright worker that talks to Laravel through the internal lease API.

Do not place business rules directly in frontend components, controllers, Eloquent observers, or queue payloads. Follow the module boundaries from `spec/Business-Watchdog-TZ.md`.

