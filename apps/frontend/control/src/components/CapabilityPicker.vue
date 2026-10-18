<script setup lang="ts">
import type { StaffCatalog } from '@/api';

const props = defineProps<{ modelValue: string[]; catalog: Pick<StaffCatalog, 'capability_catalog' | 'presets' | 'implies'> }>();
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

function ordered(codes: Set<string>): string[] {
  return props.catalog.capability_catalog.filter((item) => !item.owner_only && codes.has(item.code)).map((item) => item.code);
}

function withImplied(codes: Set<string>): Set<string> {
  for (const code of [...codes]) for (const implied of props.catalog.implies[code] ?? []) codes.add(implied);

  return codes;
}

function toggle(code: string, on: boolean): void {
  const codes = new Set(props.modelValue);

  if (on) {
    codes.add(code);
    withImplied(codes);
  } else {
    codes.delete(code);
    for (const [other, implied] of Object.entries(props.catalog.implies)) if (implied.includes(code)) codes.delete(other);
  }

  emit('update:modelValue', ordered(codes));
}

function usePreset(preset: string): void {
  emit('update:modelValue', ordered(withImplied(new Set(props.catalog.presets[preset] ?? []))));
}

function requiredBy(code: string): boolean {
  return Object.entries(props.catalog.implies).some(([other, implied]) => implied.includes(code) && props.modelValue.includes(other));
}
</script>

<template>
  <fieldset class="flex flex-col gap-3" data-capabilities>
    <legend class="font-medium">{{ $t('staff.access') }}</legend>
    <div class="flex flex-wrap items-center gap-2 text-[13px]">
      <span class="text-text-muted">{{ $t('staff.presets') }}</span>
      <button v-for="preset in Object.keys(catalog.presets)" :key="preset" type="button" class="rounded-md border border-border-strong px-2.5 py-1 font-medium text-primary hover:bg-primary-soft" :data-preset="preset" @click="usePreset(preset)">{{ $t(`role.${preset}`) }}</button>
    </div>
    <ul class="grid gap-2 sm:grid-cols-2">
      <li v-for="item in catalog.capability_catalog" :key="item.code">
        <label class="flex items-start gap-2" :class="item.owner_only && 'opacity-60'">
          <input
            type="checkbox"
            class="mt-0.5"
            :checked="!item.owner_only && modelValue.includes(item.code)"
            :disabled="item.owner_only || requiredBy(item.code)"
            :data-capability="item.code"
            @change="toggle(item.code, ($event.target as HTMLInputElement).checked)"
          />
          <span class="flex flex-col">
            <span>{{ $t(`capability.${item.code.replace('.', '_')}.label`) }}</span>
            <span class="text-[13px] text-text-muted">{{ item.owner_only ? $t('staff.owner_only') : $t(`capability.${item.code.replace('.', '_')}.hint`) }}</span>
          </span>
        </label>
      </li>
    </ul>
  </fieldset>
</template>
