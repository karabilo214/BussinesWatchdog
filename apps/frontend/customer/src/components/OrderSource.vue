<script setup lang="ts">
import { computed, ref } from 'vue';
import { listStoreIntegrations } from '@/api/stores';
import type { Integration } from '@/api/types';
import ConnectorPanel from './ConnectorPanel.vue';
import ShopwarePanel from './ShopwarePanel.vue';
import WooCommerceApiPanel from './WooCommerceApiPanel.vue';

type Source = 'api' | 'plugin';

const props = withDefaults(defineProps<{ storeId: string; canManage: boolean; platform?: string; baseUrl?: string }>(), { platform: 'woocommerce', baseUrl: '' });
const emit = defineEmits<{ changed: [] }>();

const integrations = ref<Integration[]>([]);
const showPlugin = ref(false);

const current = computed<Record<Source, Integration | null>>(() => {
  const live = (provider: string) => integrations.value.find((item) => item.provider === provider && ['active', 'degraded', 'pending'].includes(item.status)) ?? null;

  return { api: live('woocommerce_api'), plugin: live('woocommerce') };
});

async function load(): Promise<void> {
  try {
    integrations.value = await listStoreIntegrations(props.storeId);
    if (integrations.value.some((item) => item.provider === 'woocommerce' && ['active', 'degraded', 'pending'].includes(item.status))) showPlugin.value = true;
  } catch {
    integrations.value = [];
  }
}

async function changed(): Promise<void> {
  await load();
  emit('changed');
}

void load();
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="order-source-title" data-panel="connector">
    <div class="flex flex-col gap-1">
      <h2 id="order-source-title" class="text-lg font-semibold">{{ $t('store.order_source.title') }}</h2>
      <p class="max-w-3xl text-sm text-text-muted">{{ $t('store.order_source.subtitle') }}</p>
    </div>

    <ShopwarePanel v-if="platform === 'shopware'" :store-id="storeId" :base-url="baseUrl" :can-manage="canManage" @changed="changed" />

    <div v-if="platform !== 'shopware'" class="flex flex-col gap-4">
      <div class="rounded-lg border border-border bg-surface-muted p-4">
        <h3 class="font-semibold">{{ $t('store.order_source.primary_woocommerce') }}</h3>
        <p class="mt-1 text-sm text-text-muted">{{ $t('store.order_source.primary_hint') }}</p>
        <WooCommerceApiPanel :store-id="storeId" :base-url="baseUrl" :can-manage="canManage" :plugin-active="current.plugin !== null" @changed="changed" />
      </div>
      <div class="rounded-lg border border-border p-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h3 class="font-medium">{{ $t('store.order_source.extra_woocommerce') }}</h3>
            <p class="text-sm text-text-muted">{{ $t('store.order_source.extra_hint') }}</p>
          </div>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium hover:bg-surface-muted" :aria-expanded="showPlugin" @click="showPlugin = !showPlugin">{{ $t('store.order_source.extra_toggle') }}</button>
        </div>
        <ConnectorPanel v-if="showPlugin" :store-id="storeId" :can-manage="canManage" :api-active="current.api !== null" @changed="changed" />
      </div>
    </div>
  </section>
</template>
