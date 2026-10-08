# Reference Database Schema

Reference DDL copied from `spec/database`:

- `schema.sql` — base domain schema.
- `platform-billing-extension.sql` — SaaS billing and platform extension.

Apply order for review databases: base schema first, extension second. Production implementation must convert these into Laravel migrations while preserving tenant/composite constraints and money semantics.

