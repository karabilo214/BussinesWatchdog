<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { getStore } from '@/api/stores';
import type { Store } from '@/api/types';
import ConnectorPanel from '@/components/ConnectorPanel.vue';
import CoverageList from '@/components/CoverageList.vue';
import PaymentProviders from '@/components/PaymentProviders.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StoreSettings from '@/components/StoreSettings.vue';
import VerificationPanel from '@/components/VerificationPanel.vue';
import { storeHost } from '@/components/storeHost';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';
import { StatusBadge } from '@bw/ui';

const props = defineProps<{ id: string }>();
const { canManageStores } = useRole();

const store = ref<Store | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);

const host = computed(() => (store.value ? storeHost(store.value.base_url) : ''));

async function load(): Promise<void> {
  error.value = null;

  try {
    store.value = await getStore(props.id);
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

watch(
  () => props.id,
  () => {
    loading.value = true;
    store.value = null;
    void load();
  },
  { immediate: true },
);
</script>

<template>
  <AppLayout>
    <div class="flex flex-col gap-6">
      <RouterLink :to="{ name: 'overview' }" class="text-sm text-primary hover:underline">← {{ $t('nav.overview') }}</RouterLink>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>

      <ErrorNotice v-else-if="error && !store" :error="error">
        <button type="button" class="mt-2 self-start rounded-md bg-primary px-3 py-1.5 font-medium text-text-inverse hover:bg-primary-hover" @click="load">
          {{ $t('common.retry') }}
        </button>
      </ErrorNotice>

      <template v-else-if="store">
        <header class="flex flex-wrap items-start justify-between gap-3">
          <div class="flex min-w-0 flex-col gap-1">
            <h1 class="truncate text-2xl font-semibold">{{ store.name }}</h1>
            <p class="truncate text-sm text-text-muted">{{ host }} · {{ $t(`store_status.${store.status}`) }}</p>
          </div>
          <RouterLink v-if="store.active_incident_count > 0" :to="{ name: 'incidents', query: { store: store.id } }" class="rounded-full focus-visible:outline-2">
            <StatusBadge tone="crit" solid :label="$t('overview.active_incidents', { n: store.active_incident_count })" />
          </RouterLink>
          <StatusBadge v-else tone="ok" :label="$t('overview.no_incidents')" />
        </header>

        <section class="rounded-xl border border-border bg-surface p-5" aria-labelledby="coverage-title">
          <h2 id="coverage-title" class="mb-4 text-lg font-semibold">{{ $t('store.coverage_title') }}</h2>
          <CoverageList :coverage="store.coverage" />
        </section>

        <ConnectorPanel :store-id="store.id" :can-manage="canManageStores" @changed="load" />
        <PaymentProviders :store-id="store.id" :can-manage="canManageStores" @changed="load" />
        <VerificationPanel :key="store.verified_at ?? 'unverified'" :store="store" :can-manage="canManageStores" @verified="load" />
        <StoreSettings :store="store" :can-manage="canManageStores" @updated="store = $event" @stale="load" />
      </template>
    </div>
  </AppLayout>
</template>
