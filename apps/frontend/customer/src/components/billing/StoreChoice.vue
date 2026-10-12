<script setup lang="ts">
import type { Store } from '@/api/types';

const props = defineProps<{ stores: Store[]; limit: number; modelValue: string[] }>();
const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

function toggle(id: string, checked: boolean): void {
  emit('update:modelValue', checked ? [...props.modelValue, id] : props.modelValue.filter((item) => item !== id));
}
</script>

<template>
  <fieldset class="flex flex-col gap-2" data-choice="stores">
    <legend class="mb-1 text-sm">{{ $t('billing.change.choose_stores', { n: limit }) }}</legend>
    <label v-for="store in stores" :key="store.id" class="flex items-center gap-2 text-sm">
      <input
        type="checkbox"
        :checked="modelValue.includes(store.id)"
        :disabled="!modelValue.includes(store.id) && modelValue.length >= limit"
        :data-store-choice="store.id"
        @change="toggle(store.id, ($event.target as HTMLInputElement).checked)"
      />
      <span class="font-medium">{{ store.name }}</span>
      <span class="truncate text-text-muted">{{ store.base_url }}</span>
    </label>
    <p class="text-[13px] text-text-muted">{{ $t('billing.change.stores_kept') }}</p>
  </fieldset>
</template>
