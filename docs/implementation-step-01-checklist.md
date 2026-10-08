# Step 01 Checklist: Local Infrastructure Bootstrap

Stage: D1 bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 4, 6, 25, 28, 31, 34, 36; `spec/ACCEPTANCE.md` ACC-01, ACC-10, ACC-11.

This step prepares the local infrastructure only. It does not implement Laravel, Vue, the WooCommerce plugin, browser worker, migrations, billing, or business rules.

## Goals

- Install Docker Desktop or Docker Engine with Compose v2.
- Provide a reproducible local infrastructure stack.
- Avoid production credentials and real provider connections.
- Keep app/runtime artifacts out of git.
- Create a foundation for D1 auth/tenancy/store bootstrap.

## Local Tooling

- [ ] Install Docker Desktop on macOS Apple Silicon, or Docker Engine on Linux amd64.
- [ ] Confirm `docker --version` works.
- [ ] Confirm `docker compose version` works.
- [ ] If Docker Desktop is installed but `docker` is not in `PATH`, confirm `make check-tools` can find `/Applications/Docker.app/Contents/Resources/bin/docker`.
- [ ] Confirm Docker Desktop credential helpers are reachable. The `Makefile` prepends `/Applications/Docker.app/Contents/Resources/bin` to `PATH` for this.
- [ ] Copy `.env.example` to `.env` using `make setup`.
- [ ] Review local-only credentials in `.env`.

## Containers

- [ ] PostgreSQL 18 starts and passes healthcheck.
- [ ] Redis starts with append-only persistence and passes healthcheck.
- [ ] MinIO starts and console is reachable.
- [ ] Mailpit starts and UI is reachable.
- [ ] Data persists across `make down` and returns after `make up`.
- [ ] `make reset-infra` removes local volumes when a clean reset is needed.

## Expected Local Endpoints

- PostgreSQL: `localhost:${POSTGRES_PORT:-5432}`
- Redis: `localhost:${REDIS_PORT:-6379}`
- MinIO API: `http://localhost:${MINIO_API_PORT:-9000}`
- MinIO Console: `http://localhost:${MINIO_CONSOLE_PORT:-9001}`
- Mailpit UI: `http://localhost:${MAILPIT_UI_PORT:-8025}`

## Verification Commands

Run these from a normal macOS terminal, not from a restricted Codex shell, if Docker socket access is blocked.

```sh
make setup
make check-tools
make up
make ps
make logs
make down
```

Expected successful `make ps` services:

- `business-watchdog-postgres-1`
- `business-watchdog-redis-1`
- `business-watchdog-minio-1`
- `business-watchdog-mailpit-1`

## Not Done In This Step

- Laravel application bootstrap.
- PostgreSQL Laravel migrations.
- Reference DDL execution.
- Queue workers or Horizon.
- Frontend, browser worker, or WordPress plugin containers.
- Stripe/WooCommerce sandbox compatibility spike.
- Production image digest pinning.

## Notes

- Docker is not available in the current Codex execution environment, so the stack configuration was prepared but not run here.
- Image tags are centralized in `.env.example`; D0 must pin exact production image tags/digests after compatibility verification.
- Reference SQL is not mounted into PostgreSQL automatically because production schema must be created through reviewed Laravel migrations.
