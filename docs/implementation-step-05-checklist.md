# Step 05 Checklist: TenantContext Foundation

Stage: D1 bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 7, 25.3, 26, 28, 36; `spec/ACCEPTANCE.md` ACC-02, ACC-03.

This step adds the fail-closed tenant context primitive that future services, jobs, repositories, exports, and artifact access must require.

## Implementation

- [x] Add `TenantContext`.
- [x] Add explicit `MissingTenantContext` exception.
- [x] Bind `TenantContext` as a scoped service in Laravel's container.
- [x] Add `requireTenantId()` fail-closed API.
- [x] Add `scope()` helper that restores previous context after success or exception.

## Tests

- [x] Unit test missing context throws.
- [x] Unit test active context returns tenant id and source.
- [x] Unit test scoped context restores previous context.
- [x] Unit test scoped context restores after exception.
- [x] Feature test container scoped binding.

## Not Done In This Step

- Tenant resolution middleware.
- Auth-backed active tenant selection.
- Repository enforcement.
- Job middleware.
- Policies and permission matrix.
