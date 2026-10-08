# Step 10 Checklist: Tenant Role Policy Foundation

Stage: D1 bootstrap
Spec references: `spec/Business-Watchdog-TZ.md` sections 7, 24, 25.3, 26, 36; `spec/ACCEPTANCE.md` ACC-02, ACC-03 and access matrix checks.

This step adds a small route middleware foundation for tenant role authorization. It does not implement the complete permission matrix or platform staff guards.

## Implementation

- [x] Add tenant role constants.
- [x] Add route middleware `tenant.role`.
- [x] Check role against active TenantContext membership.
- [x] Apply read roles to store list/detail.
- [x] Apply manage roles to store create/update.

## Policy

- [x] Store read: owner, admin, operator, viewer.
- [x] Store manage: owner, admin.

## Tests

- [x] Viewer cannot create store.
- [x] Operator can read store.
- [x] Operator cannot update store.
- [x] Admin can create store.
- [x] Admin can update store.
- [x] Run `php artisan test`; 27 tests passed with 78 assertions.

## Not Done In This Step

- Full permission matrix for incidents/checks/integrations/billing.
- Platform owner/admin/support/billing guards.
- Policy classes per model.
- Audit log for denied actions.
