<script setup lang="ts">
import { computed, ref } from 'vue';
import { listStoreIntegrations } from '@/api/stores';
import type { Integration } from '@/api/types';
import { StatusBadge } from '@bw/ui';
import ConnectorPanel from './ConnectorPanel.vue';
import { INTEGRATION_TONES } from './integrationTones';
import ShopwarePanel from './ShopwarePanel.vue';
import WooCommerceApiPanel from './WooCommerceApiPanel.vue';

type Source = 'api' | 'plugin';

const SOURCES: { id: Source; provider: string }[] = [
  { id: 'api', provider: 'woocommerce_api' },
  { id: 'plugin', provider: 'woocommerce' },
];

const props = withDefaults(defineProps<{ storeId: string; canManage: boolean; platform?: string }>(), { platform: 'woocommerce' });
const emit = defineEmits<{ changed: [] }>();

const integrations = ref<Integration[]>([]);
const loaded = ref(false);
const chosen = ref<Source | null>(null);

const current = computed<Record<Source, Integration | null>>(() => {
  const live = (provider: string) => integrations.value.find((item) => item.provider === provider && ['active', 'degraded', 'pending'].includes(item.status)) ?? null;

  return { api: live('woocommerce_api'), plugin: live('woocommerce') };
});
const active = computed<Source>(() => chosen.value ?? (current.value.plugin !== null && current.value.api === null ? 'plugin' : 'api'));

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

function select(source: Source, event?: KeyboardEvent): void {
  chosen.value = source;

  if (event) {
    (document.getElementById(`source-tab-${source}`) as HTMLElement | null)?.focus();
  }
}

function move(event: KeyboardEvent): void {
  const index = SOURCES.findIndex((source) => source.id === active.value);
  const next = event.key === 'ArrowRight' ? (index + 1) % SOURCES.length : (index - 1 + SOURCES.length) % SOURCES.length;
  select(SOURCES[next]!.id, event);
}

void load();
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="order-source-title" data-panel="connector">
    <div class="flex flex-col gap-1">
      <h2 id="order-source-title" class="text-lg font-semibold">{{ $t('store.order_source.title') }}</h2>
      <p class="max-w-3xl text-sm text-text-muted">{{ $t('store.order_source.subtitle') }}</p>
    </div>

    <ShopwarePanel v-if="platform === 'shopware'" :store-id="storeId" :can-manage="canManage" @changed="changed" />

    <div v-if="platform !== 'shopware'" role="tablist" :aria-label="$t('store.order_source.title')" class="flex w-fit flex-wrap gap-1 rounded-lg border border-border bg-surface p-1" @keydown.right.prevent="move" @keydown.left.prevent="move">
      <button
        v-for="source in SOURCES"
        :id="`source-tab-${source.id}`"
        :key="source.id"
        type="button"
        role="tab"
        :aria-selected="active === source.id"
        :aria-controls="`source-tabpanel-${source.id}`"
        :tabindex="active === source.id ? 0 : -1"
        class="flex items-center gap-2 rounded-md px-3 py-1.5 text-sm font-medium"
        :class="active === source.id ? 'bg-primary-soft text-primary' : 'text-text-muted hover:bg-surface-muted hover:text-text'"
        :data-source-tab="source.id"
        @click="select(source.id)"
      >
        {{ $t(`store.order_source.tab.${source.id}`) }}
        <template v-if="loaded">
          <StatusBadge v-if="current[source.id]" :tone="INTEGRATION_TONES[current[source.id]!.status]" :label="$t(`provider.status.${current[source.id]!.status}`)" />
          <StatusBadge v-else-if="source.id === 'api' && current.plugin === null" tone="note" :label="$t('store.order_source.recommended')" />
        </template>
      </button>
    </div>

    <div v-if="platform !== 'shopware'" :id="`source-tabpanel-${active}`" role="tabpanel" :aria-labelledby="`source-tab-${active}`">
      <WooCommerceApiPanel v-if="active === 'api'" :store-id="storeId" :can-manage="canManage" :plugin-active="current.plugin !== null" @changed="changed" />
      <ConnectorPanel v-else :store-id="storeId" :can-manage="canManage" :api-active="current.api !== null" @changed="changed" />
    </div>
  </section>
</template>
