<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { getStore } from '@/api/stores';
import type { Store } from '@/api/types';
import OrderSource from '@/components/OrderSource.vue';
import CoverageList from '@/components/CoverageList.vue';
import PaymentProviders from '@/components/PaymentProviders.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import CheckoutLayoutSettings from '@/components/CheckoutLayoutSettings.vue';
import StoreSettings from '@/components/StoreSettings.vue';
import VerificationPanel from '@/components/VerificationPanel.vue';
import { storeHost } from '@/components/storeHost';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';
import { StatusBadge } from '@bw/ui';

const props = defineProps<{ id: string }>();
const { canManageStores } = useRole();
const logoBase = `${import.meta.env.BASE_URL}onboarding/`;

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
            <div class="flex items-center gap-3"><img :src="`${logoBase}${store.platform}-logo.svg`" alt="" class="h-8 w-10 shrink-0 object-contain" /><h1 class="truncate text-2xl font-semibold">{{ store.name }}</h1></div>
            <p class="truncate text-sm text-text-muted">{{ $t(`store.form.platforms.${store.platform}`) }} · {{ host }} · {{ $t(`store_status.${store.status}`) }}</p>
          </div>
          <RouterLink v-if="store.active_incident_count > 0" :to="{ name: 'incidents', query: { store: store.id } }" class="rounded-full focus-visible:outline-2">
            <StatusBadge tone="crit" solid :label="$t('overview.active_incidents', { n: store.active_incident_count })" />
          </RouterLink>
          <StatusBadge v-else tone="unknown" :label="$t('overview.no_incidents')" />
        </header>

        <section class="rounded-xl border border-border bg-surface p-5" aria-labelledby="onboarding-title" data-panel="onboarding">
          <h2 id="onboarding-title" class="text-lg font-semibold">{{ $t('store.onboarding.title') }}</h2>
          <p class="mt-1 text-sm text-text-muted">{{ $t('store.onboarding.subtitle') }}</p>
          <ul class="mt-4 flex flex-col gap-2 text-sm">
            <li>{{ store.onboarding.access_verified ? '✓' : '○' }} {{ $t(store.onboarding.access_verified ? 'store.onboarding.access_ok' : 'store.onboarding.access_pending') }}</li>
            <li>{{ store.onboarding.orders_received > 0 ? '✓' : '○' }} {{ $t('store.onboarding.orders', { count: store.onboarding.orders_received }) }}</li>
            <li v-if="store.onboarding.scheduled_sync_applicable">{{ store.onboarding.first_scheduled_sync_at ? '✓' : '○' }} {{ $t(store.onboarding.first_scheduled_sync_at ? 'store.onboarding.sync_ok' : 'store.onboarding.sync_pending') }}</li>
            <li>{{ store.onboarding.provider_connected ? '✓' : '○' }} {{ $t(store.onboarding.provider_connected ? 'store.onboarding.provider_ok' : 'store.onboarding.provider_pending') }}</li>
            <li>{{ store.onboarding.telegram_notifications_ready ? '✓' : '○' }} {{ $t(store.onboarding.telegram_notifications_ready ? 'store.onboarding.telegram_ok' : 'store.onboarding.telegram_pending') }}</li>
          </ul>
          <p v-if="store.onboarding.last_sync_error" class="mt-3 text-sm text-warn">{{ $te(`errors.${store.onboarding.last_sync_error}`) ? $t(`errors.${store.onboarding.last_sync_error}`) : $t('store.onboarding.sync_error') }}</p>
        </section>

        <section class="rounded-xl border border-border bg-surface p-5" aria-labelledby="coverage-title">
          <h2 id="coverage-title" class="mb-4 text-lg font-semibold">{{ $t('store.coverage_title') }}</h2>
          <CoverageList :coverage="store.coverage" />
        </section>

        <section class="rounded-xl border border-border bg-surface p-5" aria-labelledby="more-title">
          <h2 id="more-title" class="text-lg font-semibold">{{ $t('store.more.title') }}</h2>
          <p class="mt-1 text-sm text-text-muted">{{ $t('store.more.subtitle') }}</p>
          <ul class="mt-3 flex flex-wrap gap-2 text-sm">
            <li><a href="#payment-providers" class="text-primary hover:underline">{{ $t('store.more.payment') }}</a></li>
            <li><RouterLink :to="{ name: 'notifications' }" class="text-primary hover:underline">{{ $t('store.more.telegram') }}</RouterLink></li>
            <li><a href="#order-source" class="text-primary hover:underline">{{ $t('store.more.observation') }}</a></li>
            <li><a href="#browser-checks" class="text-primary hover:underline">{{ $t('store.more.browser') }}</a></li>
          </ul>
        </section>
        <div id="order-source"><OrderSource :store-id="store.id" :can-manage="canManageStores" :platform="store.platform" :base-url="store.base_url" @changed="load" /></div>
        <div id="payment-providers"><PaymentProviders :store-id="store.id" :can-manage="canManageStores" @changed="load" /></div>
        <div id="browser-checks"><VerificationPanel :key="store.verified_at ?? 'unverified'" :store="store" :can-manage="canManageStores" @verified="load" /></div>
        <StoreSettings :store="store" :can-manage="canManageStores" @updated="store = $event" @stale="load" />
        <CheckoutLayoutSettings :store="store" :can-manage="canManageStores" @updated="store = $event" />
      </template>
    </div>
  </AppLayout>
</template>
