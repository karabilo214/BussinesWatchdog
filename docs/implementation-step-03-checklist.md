# Step 03 Checklist: Backend Runtime Bootstrap

Stage: D1 bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 4, 6, 25, 26, 28, 34, 36.

This step prepares a PHP 8.4 Docker runtime for the Laravel backend. It does not implement auth, tenancy, stores, migrations, or business rules yet.

## Runtime

- [x] Add PHP 8.4 CLI image for Composer and artisan commands.
- [x] Add PHP 8.4 FPM image for backend runtime.
- [x] Install required PHP extensions for D1: `pdo_pgsql`, `redis`, `intl`, `bcmath`, `zip`, `opcache`.
- [x] Add nginx config for Laravel `public/index.php`.
- [x] Add Compose `app` profile for backend/nginx.
- [x] Add Makefile targets for backend build/create/up/logs/shell.

## Local Commands

```sh
make backend-build
make backend-create
make backend-up
```

Expected backend URL after Laravel is generated:

- `http://localhost:${BACKEND_HTTP_PORT:-8080}`

## Not Done In This Step

- Laravel application generated and verified.
- Backend health endpoints.
- Laravel migrations.
- Sanctum/auth/tenancy.
- CI pipeline.

