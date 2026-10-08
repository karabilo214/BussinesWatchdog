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

## Application

- [x] Laravel application generated in `apps/backend`.
- [x] Backend `.env.example` points at local Postgres, Redis, Mailpit, and S3Mock.
- [x] `/health/live` endpoint added.
- [x] `/health/ready` endpoint added.

## HTTP Verification

- [x] Backend container served over HTTP through nginx.
- [x] `GET /health/live` returned `status=ok`.
- [x] `GET /health/ready` returned `status=ok`.
- [x] Ready checks confirmed database, Redis, and cache.

## Not Done In This Step

- Laravel migrations.
- Sanctum/auth/tenancy.
- CI pipeline.
