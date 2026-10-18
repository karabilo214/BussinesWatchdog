<script setup lang="ts">
import type { Store } from '@/api/types';
import { useFormat } from '@/composables/useFormat';

defineProps<{ pause: NonNullable<Store['last_capacity_pause']> }>();
const { dateTime } = useFormat();
</script>

<template>
  <p class="rounded-md border border-unknown-border bg-unknown-soft px-3 py-2 text-sm text-unknown" data-pause-deviation>
    {{ $t('overview.pause_deviation', { from: dateTime(pause.from), until: dateTime(pause.until) }) }}
  </p>
</template>
