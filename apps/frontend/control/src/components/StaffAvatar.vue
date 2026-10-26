<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{ name: string | null; photoUrl: string | null; size?: 'sm' | 'md' | 'lg' }>(), { size: 'sm' });

const initials = computed(() => (props.name ?? '?').split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]!.toUpperCase()).join('') || '?');
const box = computed(() => ({ sm: 'size-7 text-[11px]', md: 'size-10 text-sm', lg: 'size-24 text-2xl' })[props.size]);
</script>

<template>
  <img v-if="photoUrl" :src="photoUrl" alt="" class="shrink-0 rounded-full object-cover" :class="box" data-avatar="photo" />
  <span v-else class="inline-flex shrink-0 items-center justify-center rounded-full bg-primary-soft font-semibold text-primary" :class="box" aria-hidden="true" data-avatar="initials">{{ initials }}</span>
</template>
