import { computed } from 'vue';
import type { TenantPermission } from '@/api/types';
import { useSession } from './useSession';

/** The active member's role and permissions from the session (ADR 0079); the backend enforces them on every request. */
export function useRole() {
  const { session } = useSession();

  const membership = computed(() => {
    const current = session.value;

    return current?.memberships.find((item) => item.tenant_id === current.active_tenant_id) ?? null;
  });

  const role = computed(() => membership.value?.role ?? null);
  const permissions = computed((): readonly TenantPermission[] => membership.value?.permissions ?? []);

  function can(permission: TenantPermission): boolean {
    return permissions.value.includes(permission);
  }

  return {
    role,
    permissions,
    can,
    canManageStores: computed(() => can('stores.manage')),
    canManageIntegrations: computed(() => can('integrations.manage')),
    canRunChecks: computed(() => can('checks.run')),
    canReadReconciliation: computed(() => can('reconciliation.read')),
    canRunReconciliation: computed(() => can('reconciliation.run')),
    canAllocate: computed(() => can('allocations.manage')),
    canReadIncidents: computed(() => can('incidents.read')),
    canHandleIncidents: computed(() => can('incidents.manage')),
    canSnoozeIncidents: computed(() => can('incidents.snooze')),
    canManageNotifications: computed(() => can('notifications.manage')),
    canManageTeam: computed(() => can('team.manage')),
  };
}
