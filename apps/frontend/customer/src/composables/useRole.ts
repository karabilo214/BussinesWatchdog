import { computed } from 'vue';
import { useSession } from './useSession';

/** Mirrors TenantRoles::storeManage() on the backend; the backend still enforces it. */
const STORE_MANAGERS = ['owner', 'admin'];

export function useRole() {
  const { session } = useSession();

  const role = computed(() => {
    const current = session.value;

    return current?.memberships.find((membership) => membership.tenant_id === current.active_tenant_id)?.role ?? null;
  });

  const canManageStores = computed(() => role.value !== null && STORE_MANAGERS.includes(role.value));

  return { role, canManageStores };
}
