<script setup lang="ts">
import { computed } from 'vue';
import type { PermissionCatalog, PresetRole, TenantPermission } from '@/api/types';

const props = defineProps<{ idPrefix: string; catalog: PermissionCatalog; assignable: TenantPermission[]; presets: PresetRole[] }>();
const model = defineModel<TenantPermission[]>({ required: true });

const MONEY: TenantPermission = 'money.view';

/** The preset the list matches, ignoring amounts — the same rule as the server (ADR 0079). */
const preset = computed<PresetRole | 'custom'>(() => {
  const current = [...model.value].filter((permission) => permission !== MONEY).sort();
  const found = (Object.keys(props.catalog.presets) as PresetRole[]).find((role) => {
    const expected = [...props.catalog.presets[role]].sort();

    return expected.length === current.length && expected.every((permission, index) => permission === current[index]);
  });

  return found ?? 'custom';
});

function key(permission: TenantPermission): string {
  return permission.replace('.', '_');
}

function state(permission: TenantPermission): 'base' | 'owner' | 'locked' | 'free' {
  if (props.catalog.base.includes(permission)) return 'base';
  if (props.catalog.owner_only.includes(permission)) return 'owner';

  return props.assignable.includes(permission) ? 'free' : 'locked';
}

function requiredBy(permission: TenantPermission): TenantPermission[] {
  return (Object.entries(props.catalog.implies) as [TenantPermission, TenantPermission[]][])
    .filter(([, implied]) => implied.includes(permission))
    .map(([owner]) => owner);
}

/** Checking brings what the permission needs; unchecking also drops what depended on it. */
function toggle(permission: TenantPermission, on: boolean): void {
  const next = new Set(model.value);

  if (on) {
    next.add(permission);
    (props.catalog.implies[permission] ?? []).forEach((implied) => next.add(implied));
  } else {
    next.delete(permission);
    requiredBy(permission).forEach((dependent) => next.delete(dependent));
  }

  props.catalog.base.forEach((base) => next.add(base));
  model.value = orderedList(next);
}

function applyPreset(role: PresetRole): void {
  const next = new Set<TenantPermission>(props.catalog.presets[role]);

  if (model.value.includes(MONEY)) next.add(MONEY);

  model.value = orderedList(next);
}

function orderedList(set: Set<TenantPermission>): TenantPermission[] {
  return Object.values(props.catalog.sections).flat().filter((permission) => set.has(permission));
}
</script>

<template>
  <div class="flex flex-col gap-4" :data-permission-picker="idPrefix">
    <div class="flex flex-wrap items-center gap-2 text-sm">
      <span class="font-medium">{{ $t('team.permissions.preset') }}</span>
      <button
        v-for="role in presets"
        :key="role"
        type="button"
        class="rounded-md border px-3 py-1.5 font-medium"
        :class="preset === role ? 'border-primary bg-primary-soft text-primary' : 'border-border-strong bg-surface hover:bg-surface-muted'"
        :aria-pressed="preset === role"
        :data-preset="role"
        @click="applyPreset(role)"
      >
        {{ $t(`team.role.${role}`) }}
      </button>
      <span v-if="preset === 'custom'" class="rounded-md border border-border px-3 py-1.5 text-text-muted" data-preset="custom">{{ $t('team.role.custom') }}</span>
    </div>

    <div class="grid gap-3 sm:grid-cols-2">
      <fieldset v-for="(permissions, section) in catalog.sections" :key="section" class="flex flex-col gap-2 rounded-lg border border-border p-3" :data-section="section">
        <legend class="px-1 text-sm font-semibold">{{ $t(`team.section.${section}`) }}</legend>
        <label
          v-for="permission in permissions"
          :key="permission"
          class="flex items-start gap-2 text-sm"
          :class="{ 'text-text-muted': state(permission) !== 'free' }"
          :for="`${idPrefix}-${key(permission)}`"
        >
          <input
            :id="`${idPrefix}-${key(permission)}`"
            type="checkbox"
            class="mt-0.5 size-4 accent-primary"
            :checked="state(permission) === 'base' || state(permission) === 'owner' ? state(permission) === 'base' : model.includes(permission)"
            :disabled="state(permission) !== 'free'"
            :data-permission="permission"
            @change="toggle(permission, ($event.target as HTMLInputElement).checked)"
          />
          <span class="flex flex-col">
            <span class="font-medium">{{ $t(`team.permission.${key(permission)}.label`) }}</span>
            <span class="text-[13px] text-text-muted">{{ $t(`team.permission.${key(permission)}.hint`) }}</span>
            <span v-if="state(permission) === 'base'" class="text-[13px]">{{ $t('team.permissions.base') }}</span>
            <span v-else-if="state(permission) === 'owner'" class="text-[13px]">{{ $t('team.permissions.owner_only') }}</span>
            <span v-else-if="state(permission) === 'locked'" class="text-[13px]">{{ $t('team.permissions.not_yours') }}</span>
          </span>
        </label>
      </fieldset>
    </div>
  </div>
</template>
