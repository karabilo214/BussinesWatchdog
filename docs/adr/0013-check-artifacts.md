# ADR 0013: Browser Check Artifacts (Redacted Screenshots)

Date: 2026-10-09

Status: implemented in Step 54.

## Decisions

- **What is captured.** Only a viewport JPEG (quality 60) when an attempt does not pass, scrolled to the failing step's area. Before capture the worker paints over every input, textarea, select, select2/combobox, editable area, iframe (card fields) and address block. No full-page captures, no traces, no HAR. `redaction_version` `r1` is stored with each artifact.
- **Upload through the backend, not a presigned URL (deviation from spec §21).** The worker is isolated from object storage (ADR 0012), so it posts the image to `POST /internal/v1/browser/attempts/{id}/artifacts` with its lease token and fencing token. The backend checks the live lease, size (≤ 2 MiB), JPEG/PNG signature and SHA-256, chooses the object key (`tenants/{tenant}/stores/{store}/checks/{run}/{attempt}/{artifact}.jpg` — the worker cannot influence it), writes a private object, verifies its stored size and only then records the artifact `ready`. At most 3 per attempt; the result may reference only ready artifacts of its own attempt. This is stricter than a presigned URL: the worker never sees storage credentials or URLs.
- **Access.** `GET /api/v1/artifacts/{id}/url` after tenant authorisation returns a 60-second link (`Cache-Control: no-store`, rate-limited); run details list artifacts without object keys.
- **Retention.** 30 days (`WATCHDOG_ARTIFACTS_RETENTION_DAYS`); `artifacts:purge` daily deletes the object first, then marks the row `deleted` (safe to rerun).
- **Storage.** Disk `artifacts` (S3 driver, private, `league/flysystem-aws-s3-v3`); bucket from `WATCHDOG_ARTIFACTS_BUCKET` or `AWS_BUCKET`. Verified against S3Mock in the browser e2e (object stored, SHA-256 matches); the screenshot of the "no payment methods" case was inspected: the message is visible, all personal fields are masked.

## Not covered

Server-side encryption settings and bucket lifecycle rules are deployment configuration; traces stay disabled (spec §19).
