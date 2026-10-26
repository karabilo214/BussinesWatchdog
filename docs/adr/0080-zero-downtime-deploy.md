# ADR 0080: Zero-downtime deploy

Date: 2026-10-26

Status: accepted (owner's backlog item "Выкладка без простоя"; implemented in Step 275)

## Context

`infra/production/deploy.sh` recreated the only PHP-FPM container `app` and the `web` container (Caddy) on every deploy. The `web` image carried the release tag, the Caddyfile and the built cabinets. For a few seconds the API, the public site and incoming webhooks answered 502, and while Caddy restarted no connection on 80/443 was accepted.

## Decisions

1. **Two PHP-FPM containers.** `app` and `app-b` run the same image. `infra/production/rollout.sh` replaces them one after the other with `docker compose up -d --no-deps --wait`; a container counts as up when FPM accepts connections on port 9000. PHP-FPM stops on `SIGQUIT` and finishes the requests it has already accepted (`process_control_timeout = 25s`, `stop_grace_period: 30s`). The name `app` is kept, so every `docker compose exec app …` command in the docs still works.
2. **Caddy load-balances and retries.** `php_fastcgi app:9000 app-b:9000` with round robin, `lb_try_duration 15s` and `lb_try_interval 100ms`: a request that cannot reach one container goes to the other one instead of getting a 502. An active health check calls `/index.php/health/live` through FastCGI every 2 s (the path has to name `index.php`, because health checks skip the `php_fastcgi` rewrite). A container that fails is skipped for `fail_duration 10s`.
3. **Caddy is not recreated.** `web` uses the official `caddy:2.10.2-alpine` image with a pinned version. The Caddyfile is mounted from `infra/production/caddy/`; the directory is mounted rather than the file, so a `git checkout` is visible inside the container. Static files live in the volume `web-root`. A deploy runs `caddy reload`. The container is recreated only when its own compose definition changes, for example on a new Caddy version.
4. **Static files are switched atomically.** The image `business-watchdog/web-static:<release>` (service `web-static`, profile `tools`, no network) holds the built customer and owner cabinets and the backend `public/` folder. It is used in two steps:
   - `publish stage` copies the release to `web-root/releases/<release>`. It also copies the new hashed `assets/` into the current release, so pages already rendered by the new PHP find their files before the switch.
   - `publish switch` repoints the `current` symlink in one rename.
   
   Old hashed assets stay in the new release for 14 days, so a browser tab opened before the deploy can still load its lazy chunks. The last three releases are kept.
5. **Order of a rollout.** Build, migrations (run while the previous code is still serving), `stage`, then `app-b` and `app`, then `switch` and the Caddy reload. Workers and the scheduler go last; they finish the current job on `SIGTERM`, within `stop_grace_period` of 120 s (60 s for the scheduler). A job still running after that is killed. It stays in Redis and is run again after `retry_after` (960 s); jobs are idempotent (AGENTS.md).
6. **Migrations are expand/contract.** Every migration has to work with the code of the previous release, because that code keeps serving while the migration runs and until both FPM containers are replaced:
   - add columns as nullable or with a default; add tables and indexes freely (`CREATE INDEX CONCURRENTLY` for large tables);
   - never rename or drop a column or table the previous release uses in the same release. First stop using it, deploy, then remove it in the next release;
   - backfills that touch many rows run as a separate command or job, not inside the migration.

   A breaking change that cannot be split this way is a planned maintenance with downtime, agreed with the owner beforehand.
7. **Verification.** `infra/production/probe.sh <base-url> <seconds> [paths…]` requests each path every 100 ms and fails on any status other than 200.

## Consequences

- The first deploy that introduces this layout recreates `web` (new image) and `app` once, so it still has a short interruption. It is the last one.
- During the rollout, old and new PHP serve requests side by side for a few seconds. This is the reason for decision 6.
- Rolling back is the same rollout with the previous commit. A migration is not rolled back automatically; under decision 6 the previous code works with the newer schema anyway.
- `docker compose up -d` without a service list recreates both FPM containers at once and causes downtime again. Use `sh rollout.sh` after editing `.env` as well.
