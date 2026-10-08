# Backend Agent Notes

Follow the repository root `AGENTS.md` and the Business Watchdog specification.

Backend runtime is Docker PHP 8.4. Do not use host PHP 8.1 or install a different host PHP to satisfy Laravel commands.

Use these commands from the repository root:

- `make backend-shell` for Composer and artisan work.
- `make backend-up` to run PHP-FPM behind nginx.
- `make backend-logs` for backend/nginx logs.

Do not install Laravel Boost or other development agents unless an ADR explicitly adds them to the project.

The first backend implementation slices are health endpoints, auth/session foundation, tenancy, stores, and reviewed migrations derived from `database/reference`.
