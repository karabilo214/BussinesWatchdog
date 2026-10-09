# ADR 0004: Local Object Storage Emulator

Date: 2026-10-09

Status: accepted for local development; revisit when the artifact feature (browser screenshots/traces, exports) is implemented.

## Context

`spec/Business-Watchdog-TZ.md` lists MinIO as the local S3-compatible storage in Docker Compose. ADR 0001 recorded that the bootstrap used `adobe/s3mock:5.2.2` instead because the MinIO image could not be pulled.

Re-checked on 2026-10-09: `minio/minio:RELEASE.2025-04-22T22-12-26Z` is denied on Docker Hub and `quay.io/minio/minio` with the same tag is unauthorized. MinIO no longer distributes public community images, so "pin a MinIO image" is not an available option without building MinIO from source or adopting a different licence/distribution channel.

The backend does not use object storage yet: there is no `Storage::disk('s3')` usage and `league/flysystem-aws-s3-v3` is not installed.

## Decision

- Keep `adobe/s3mock:5.2.2` (pinned, overridable via `S3_IMAGE`) as the local S3 endpoint for development and tests.
- Application code may rely only on plain S3 API behaviour that every S3-compatible store supports: path-style addressing, PUT/GET/DELETE object, presigned GET with short TTL, server-side bucket per environment. No MinIO-specific admin APIs, notifications or lifecycle features.
- Production storage is a deployment choice (managed S3-compatible service); it is not decided here.

## Consequences

- S3Mock does not enforce IAM policies, object lock or lifecycle rules; tests of artifact access control must assert authorization in the application layer (tenant-scoped signed links), not rely on the emulator.
- When the artifact feature starts, re-evaluate: S3Mock vs another pinned, publicly distributed S3-compatible server (e.g. SeaweedFS or Garage) if bucket policies/lifecycle need local coverage.

## Tracking

- Closes ADR 0001 "Local S3 Uses S3Mock Instead Of MinIO" by formalizing it.
