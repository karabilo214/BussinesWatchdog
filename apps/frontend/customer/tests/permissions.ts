import type { PermissionCatalog, PresetRole, TeamRole, TenantPermission } from '@/api/types';

/** Mirrors TenantPermissions on the backend (ADR 0079) for test fixtures. */
export const CATALOG: PermissionCatalog = {
  sections: {
    stores: ['stores.read', 'stores.manage', 'integrations.manage', 'stores.delete'],
    checks: ['checks.run'],
    reconciliation: ['reconciliation.read', 'reconciliation.run', 'allocations.manage'],
    incidents: ['incidents.read', 'incidents.manage', 'incidents.snooze'],
    notifications: ['notifications.manage'],
    money: ['money.view'],
    settings: ['team.manage', 'billing.manage', 'ownership.transfer', 'members.mfa_reset', 'support.access', 'organization.delete'],
  },
  owner_only: ['stores.delete', 'billing.manage', 'ownership.transfer', 'members.mfa_reset', 'support.access', 'organization.delete'],
  base: ['stores.read'],
  implies: {
    'reconciliation.run': ['reconciliation.read'],
    'allocations.manage': ['reconciliation.read'],
    'incidents.manage': ['incidents.read'],
    'incidents.snooze': ['incidents.read'],
  },
  presets: {
    admin: ['stores.read', 'stores.manage', 'integrations.manage', 'checks.run', 'reconciliation.read', 'reconciliation.run', 'allocations.manage', 'incidents.read', 'incidents.manage', 'incidents.snooze', 'notifications.manage', 'team.manage'],
    operator: ['stores.read', 'reconciliation.read', 'reconciliation.run', 'incidents.read', 'incidents.manage'],
    viewer: ['stores.read', 'reconciliation.read', 'incidents.read'],
  },
};

export const ALL: TenantPermission[] = Object.values(CATALOG.sections).flat();

export function permissionsFor(role: TeamRole, money = true): TenantPermission[] {
  if (role === 'owner') return ALL;
  const preset = role === 'custom' ? CATALOG.base : CATALOG.presets[role as PresetRole];

  return ALL.filter((permission) => preset.includes(permission) || (money && permission === 'money.view'));
}

export function assignableFor(role: TeamRole): TenantPermission[] {
  if (role === 'owner') return ALL.filter((permission) => !CATALOG.owner_only.includes(permission));

  return permissionsFor(role).includes('team.manage') ? permissionsFor(role) : [];
}
