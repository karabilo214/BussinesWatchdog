<script setup lang="ts">
import { computed } from 'vue';
import type { Store } from '@/api/types';

const props = defineProps<{ stores: Store[]; modelValue: string }>();
const emit = defineEmits<{ select: [id: string] }>();

const logoBase = `${import.meta.env.BASE_URL}onboarding/`;
const selected = computed(() => props.stores.find((store) => store.id === props.modelValue) ?? null);
/** Up to five stores fit as buttons; more become a list with the chosen store shown beside it. */
const asButtons = computed(() => props.stores.length <= 5);
</script>

<template>
  <section class="flex flex-col gap-2 rounded-xl border p-3" :class="selected ? 'border-border bg-surface' : 'border-warn-border bg-warn-soft'" data-store-switcher :aria-label="$t('store_switcher.label')">
    <div class="flex flex-wrap items-center gap-2">
      <span class="text-sm font-semibold">{{ $t('store_switcher.label') }}</span>
      <template v-if="asButtons">
        <button
          v-for="store in stores"
          :key="store.id"
          type="button"
          class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm font-medium"
          :class="store.id === modelValue ? 'border-primary bg-primary-soft text-primary' : 'border-border-strong bg-surface hover:bg-surface-muted'"
          :aria-pressed="store.id === modelValue"
          :data-store="store.id"
          @click="emit('select', store.id)"
        >
          <img :src="`${logoBase}${store.platform}-logo.svg`" alt="" class="h-5 w-6 object-contain" />
          <span class="max-w-[14rem] truncate">{{ store.name }}</span>
        </button>
      </template>
      <template v-else>
        <img v-if="selected" :src="`${logoBase}${selected.platform}-logo.svg`" alt="" class="h-6 w-8 object-contain" />
        <select :value="modelValue" :aria-label="$t('store_switcher.label')" class="min-w-[14rem] rounded-lg border border-border-strong bg-surface px-3 py-2 text-base font-medium" @change="emit('select', ($event.target as HTMLSelectElement).value)">
          <option value="" disabled>{{ $t('store_switcher.choose') }}</option>
          <option v-for="store in stores" :key="store.id" :value="store.id">{{ store.name }}</option>
        </select>
      </template>
    </div>
    <p v-if="!selected" class="text-sm" data-store-required>{{ $t('store_switcher.required') }}</p>
  </section>
</template>
