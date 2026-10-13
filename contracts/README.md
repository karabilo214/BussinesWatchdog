# Contracts

Working copies of API and event contracts.

`openapi.yaml`, `platform-billing-api.yaml` and `event.schema.json` live only here (no copies in `spec/contracts`). The backend keeps a runtime copy of the event schema in `apps/backend/resources/contracts/event.schema.json`; `IngestEventsApiTest` checks it equals this file.

`ui-api-catalog.md` started as a copy of `spec/contracts/ui-api-catalog.md` and has since been extended here.

Update these files before implementing or changing the corresponding endpoints. Keep `spec/` as the prepared specification package; implementation contracts live here.

