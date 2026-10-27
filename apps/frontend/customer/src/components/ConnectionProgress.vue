<script setup lang="ts">
import { computed } from 'vue';
import { ApiError } from '@bw/api-client';

const props = defineProps<{ error: unknown; platform: 'woocommerce' | 'shopware' | 'stripe' | 'paypal' }>();
const PROVIDER_NAMES = { stripe: 'Stripe', paypal: 'PayPal' } as const;
const PROVIDER_STAGES: Record<'stripe' | 'paypal', Record<string, string[]>> = {
  stripe: {
    stripe_payment_intents_read_missing: ['format', 'reached', 'accepted'],
    stripe_charges_read_missing: ['format', 'reached', 'accepted'],
    stripe_refunds_read_missing: ['format', 'reached', 'accepted'],
    stripe_key_rejected: ['format', 'reached'],
    stripe_account_mismatch: ['format', 'reached', 'accepted', 'read'],
  },
  paypal: {
    paypal_transaction_search_missing: ['format', 'reached', 'accepted'],
    paypal_credentials_rejected: ['format', 'reached'],
  },
};

const stages = computed(() => {
  if (!(props.error instanceof ApiError)) return [];
  const code = props.error.code;
  if (props.platform === 'stripe' || props.platform === 'paypal') return PROVIDER_STAGES[props.platform][code] ?? [];
  const prefix = `${props.platform}_`;
  if (!code.startsWith(prefix)) return [];
  if (code === `${prefix}order_read_missing` || code === `${prefix}key_permission_missing`) return ['site', 'api', 'authorization'];
  if (code === `${prefix}key_rejected` || code === `${prefix}key_not_read_only`) return ['site', 'api'];
  if (['api_unavailable', 'challenge', 'basic_auth', 'maintenance'].some((suffix) => code === `${prefix}${suffix}`)) return ['site'];
  return [];
});
const provider = computed(() => (props.platform === 'stripe' || props.platform === 'paypal' ? PROVIDER_NAMES[props.platform] : ''));
</script>

<template>
  <div v-if="stages.length" class="mt-2 border-t border-crit-border pt-2 text-[13px]" data-connection-progress>
    <p class="font-medium">{{ $t('store.connection_progress.title') }}</p>
    <ul class="mt-1 flex flex-col gap-0.5">
      <li v-for="stage in stages" :key="stage">✓ {{ $t(`store.connection_progress.${stage}`, { provider }) }}</li>
    </ul>
  </div>
</template>
