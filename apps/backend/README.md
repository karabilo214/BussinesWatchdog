# Business Watchdog Backend

Laravel backend for Business Watchdog.

Runtime:

- Laravel 13
- PHP 8.4 through `infra/docker/backend/Dockerfile`
- PostgreSQL 18
- Redis
- Mailpit for local email
- S3-compatible local object storage through S3Mock

Local commands from the repository root:

```sh
make backend-build
make backend-create
make backend-up
make backend-logs
```

Health endpoints:

- `GET /health/live`
- `GET /health/ready`

The backend is currently a D1 scaffold. Auth, tenancy, store setup, migrations, durable ingestion, browser leases, reconciliation, billing, and notifications are not implemented yet.
