# Implementation Step 41 Checklist: Full JSON Schema Validation Of Ingested Events

Stage: ADR 0001 gap closure (ingest)
Spec references: `spec/Business-Watchdog-TZ.md` sections 10–11 (event envelope, schema), 29 (ingest validation, quarantine); `contracts/event.schema.json`.
Acceptance references: "invalid schema quarantine" from the reliability test list.
Closes: ADR 0001 "Event Ingress Uses Envelope Validation Before Full JSON Schema Validation".

## Scope

- [x] `opis/json-schema` ^2.6 (Draft 2020-12).
- [x] `EventSchemaValidator` loads `resources/contracts/event.schema.json` by `$id`.
- [x] `EventsController` decodes the body a second time in object form (keeps `{}` vs `[]` distinct) and passes each raw event to `EventPayloadValidator`; schema failures are quarantined as `schema_invalid`.
- [x] Non-object JSON bodies return `422 schema_invalid` instead of a server error.
- [x] Probe confirmed the schema catches cases the hand validator missed (empty `display_number`, date without time); both are regression tests.
- [x] Sync test between `contracts/event.schema.json` and the backend copy.

## Verification

- [x] pint on changed files.
- [x] Full `php artisan test` (PHP 8.4): 249 tests passed (4 new).
- [x] No schema/migration change.

## Not Done In This Step

- Schema validation of non-ingest contracts (browser DSL schema is a D4 item).
