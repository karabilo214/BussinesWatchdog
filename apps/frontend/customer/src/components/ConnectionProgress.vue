<script setup lang="ts">
import { computed } from 'vue';
import { ApiError } from '@bw/api-client';

const props = defineProps<{ error: unknown; platform: 'woocommerce' | 'shopware' }>();
const stages = computed(() => {
  if (!(props.error instanceof ApiError)) return [];
  const code = props.error.code;
  const prefix = `${props.platform}_`;
  if (!code.startsWith(prefix)) return [];
  if (code === `${prefix}order_read_missing` || code === `${prefix}key_permission_missing`) return ['site', 'api', 'authorization'];
  if (code === `${prefix}key_rejected` || code === `${prefix}key_not_read_only`) return ['site', 'api'];
  if (['api_unavailable', 'challenge', 'basic_auth', 'maintenance'].some((suffix) => code === `${prefix}${suffix}`)) return ['site'];
  return [];
});
</script>

<template>
  <div v-if="stages.length" class="mt-2 border-t border-crit-border pt-2 text-[13px]" data-connection-progress>
    <p class="font-medium">{{ $t('store.connection_progress.title') }}</p>
    <ul class="mt-1 flex flex-col gap-0.5">
      <li v-for="stage in stages" :key="stage">✓ {{ $t(`store.connection_progress.${stage}`) }}</li>
    </ul>
  </div>
</template>
