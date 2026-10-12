<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { ApiError } from '@bw/api-client';

const props = defineProps<{ error: unknown }>();
const { te } = useI18n();

const key = computed(() => {
  if (props.error instanceof ApiError) {
    const specific = `errors.${props.error.code}`;

    if (te(specific)) {
      return specific;
    }

    if (props.error.status === 0) {
      return 'errors.network';
    }
  }

  return 'errors.generic';
});
const requestId = computed(() => (props.error instanceof ApiError ? props.error.requestId : null));
</script>

<template>
  <div role="alert" class="flex flex-col gap-1 rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">
    <p>{{ $t(key) }}</p>
    <p v-if="requestId" class="font-mono text-xs">{{ $t('errors.request_id', { id: requestId }) }}</p>
  </div>
</template>
