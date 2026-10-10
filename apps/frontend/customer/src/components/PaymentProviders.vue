<script setup lang="ts">
import { computed, ref } from 'vue';
import { listStoreIntegrations } from '@/api/stores';
import type { Integration } from '@/api/types';
import { StatusBadge } from '@bw/ui';
import { INTEGRATION_TONES } from './integrationTones';
import PayPalPanel from './PayPalPanel.vue';
import ProviderPanel from './ProviderPanel.vue';

type Provider = 'stripe' | 'paypal';

const PROVIDERS: { id: Provider; label: string }[] = [
  { id: 'stripe', label: 'Stripe' },
  { id: 'paypal', label: 'PayPal' },
];

const props = defineProps<{ storeId: string; canManage: boolean }>();
const emit = defineEmits<{ changed: [] }>();

const integrations = ref<Integration[]>([]);
const loaded = ref(false);
const chosen = ref<Provider | null>(null);

const current = computed<Record<Provider, Integration | null>>(() => ({
  stripe: integrations.value.find((item) => item.provider === 'stripe' && ['active', 'degraded', 'pending'].includes(item.status)) ?? null,
  paypal: integrations.value.find((item) => item.provider === 'paypal' && ['active', 'degraded', 'pending'].includes(item.status)) ?? null,
}));
const active = computed<Provider>(() => chosen.value ?? PROVIDERS.find((provider) => current.value[provider.id] !== null)?.id ?? 'stripe');

async function load(): Promise<void> {
  try {
    integrations.value = await listStoreIntegrations(props.storeId);
  } catch {
    integrations.value = [];
  } finally {
    loaded.value = true;
  }
}

async function changed(): Promise<void> {
  await load();
  emit('changed');
}

function select(provider: Provider, event?: KeyboardEvent): void {
  chosen.value = provider;

  if (event) {
    (document.getElementById(`provider-tab-${provider}`) as HTMLElement | null)?.focus();
  }
}

function move(event: KeyboardEvent): void {
  const index = PROVIDERS.findIndex((provider) => provider.id === active.value);
  const next = event.key === 'ArrowRight' ? (index + 1) % PROVIDERS.length : (index - 1 + PROVIDERS.length) % PROVIDERS.length;
  select(PROVIDERS[next]!.id, event);
}

void load();
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="provider-title" data-panel="provider">
    <div class="flex flex-col gap-1">
      <h2 id="provider-title" class="text-lg font-semibold">{{ $t('provider.title') }}</h2>
      <p class="max-w-3xl text-sm text-text-muted">{{ $t('provider.subtitle') }}</p>
    </div>

    <div role="tablist" :aria-label="$t('provider.title')" class="flex w-fit flex-wrap gap-1 rounded-lg border border-border bg-surface p-1" @keydown.right.prevent="move" @keydown.left.prevent="move">
      <button
        v-for="provider in PROVIDERS"
        :id="`provider-tab-${provider.id}`"
        :key="provider.id"
        type="button"
        role="tab"
        :aria-selected="active === provider.id"
        :aria-controls="`provider-tabpanel-${provider.id}`"
        :tabindex="active === provider.id ? 0 : -1"
        class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium"
        :class="active === provider.id ? 'bg-primary-soft text-primary' : 'text-text-muted hover:bg-surface-muted hover:text-text'"
        :data-provider-tab="provider.id"
        @click="select(provider.id)"
      >
        {{ provider.label }}
        <template v-if="loaded">
          <StatusBadge v-if="current[provider.id]" :tone="INTEGRATION_TONES[current[provider.id]!.status]" :label="$t(`provider.status.${current[provider.id]!.status}`)" />
          <StatusBadge v-else tone="unknown" :label="$t('provider.not_connected')" />
        </template>
      </button>
    </div>

    <div :id="`provider-tabpanel-${active}`" role="tabpanel" :aria-labelledby="`provider-tab-${active}`">
      <ProviderPanel v-if="active === 'stripe'" :store-id="storeId" :can-manage="canManage" @changed="changed" />
      <PayPalPanel v-else :store-id="storeId" :can-manage="canManage" @changed="changed" />
    </div>
  </section>
</template>
