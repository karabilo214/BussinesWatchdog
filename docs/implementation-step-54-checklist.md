# Implementation Step 54 Checklist: Check Artifacts (Redacted Screenshots)

Stage: P0 (spec §19, §21; ADR 0013)

## Scope

- [x] `artifacts` table (reference schema), disk `artifacts` (S3, private), `league/flysystem-aws-s3-v3`.
- [x] Internal upload bound to the live lease (size, signature, SHA-256, backend-chosen key, max 3 per attempt); result accepts only ready artifacts of its own attempt.
- [x] User API: artifacts in run details (no keys), 60-second link endpoint; OpenAPI updated.
- [x] `artifacts:purge` daily (object first, then row).
- [x] Worker: masked viewport JPEG on non-passed attempts, focused on the failing step; upload failure never changes the outcome.

## Verification

- [x] Backend: 3 new tests (storage + link + tenant isolation, rejection cases, purge); SQLite 302 passed + 4 skipped, PostgreSQL 18 306 passed.
- [x] Worker unit tests 13/13.
- [x] Browser e2e on all six targets with S3Mock: the failing run stores one JPEG whose SHA-256 matches; screenshot inspected (failure visible, personal fields masked).
