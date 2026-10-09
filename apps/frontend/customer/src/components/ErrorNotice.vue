<script setup lang="ts">
import { computed } from 'vue';
import { errorKey, requestIdOf } from '@/composables/errors';

const props = defineProps<{ error: unknown }>();

const key = computed(() => errorKey(props.error));
const requestId = computed(() => requestIdOf(props.error));
</script>

<template>
  <div role="alert" class="flex flex-col gap-1 rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">
    <p>{{ $t(key) }}</p>
    <p v-if="requestId" class="font-mono text-xs">{{ $t('common.request_id', { id: requestId }) }}</p>
    <slot />
  </div>
</template>
