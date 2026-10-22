# Backend bootstrap history

This file recorded the D1 starting point. The Laravel backend is implemented; see
`../README.md`, `../../../PROJECT_STATE.md` and `../../../docs/ops/setup.md` for current behavior and operation.

The original target stack was:

- Laravel 13
- PHP 8.4
- PostgreSQL 18
- Redis + Horizon
- Sanctum same-origin sessions

The initial implementation sequence was:

1. Create Laravel application files.
2. Add health endpoints.
3. Add auth/session foundation.
4. Add tenant/store bootstrap migrations from `database/reference`.
5. Keep reference SQL as reviewed design input, not direct production DDL.
