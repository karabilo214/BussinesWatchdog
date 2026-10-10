<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { ApiError } from '@bw/api-client';
import { useRoute, useRouter } from 'vue-router';
import { listScenarios } from '@/api/checks';
import { getStore, listStores, updateStore } from '@/api/stores';
import type { CheckScenario, Store } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import RunsList from '@/components/checks/RunsList.vue';
import ScenarioPanel from '@/components/checks/ScenarioPanel.vue';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';
import { StatusBadge } from '@bw/ui';

const route = useRoute();
const router = useRouter();
const { canManageStores } = useRole();

const stores = ref<Store[]>([]);
const loading = ref(true);
const error = ref<unknown>(null);
const scenario = ref<CheckScenario | null>(null);
const scenarioLoading = ref(false);
const scenarioError = ref<unknown>(null);
const toggling = ref(false);
const toggleError = ref<unknown>(null);

const storeId = computed(() => (typeof route.query.store === 'string' ? route.query.store : '') || stores.value[0]?.id || '');
const store = computed(() => stores.value.find((item) => item.id === storeId.value) ?? null);

interface Condition {
  key: string;
  met: boolean;
}

const conditions = computed<Condition[]>(() => {
  const current = store.value;

  if (current === null) return [];

  return [
    { key: 'verified', met: current.verified_at !== null },
    { key: 'active', met: current.status === 'active' },
    { key: 'browser_enabled', met: current.browser_enabled },
    { key: 'scenario', met: scenario.value?.enabled === true },
  ];
});
const ready = computed(() => conditions.value.every((condition) => condition.met));

function replaceStore(updated: Store): void {
  stores.value = stores.value.map((item) => (item.id === updated.id ? updated : item));
}

async function refreshStore(): Promise<void> {
  if (store.value === null) return;

  replaceStore(await getStore(store.value.id));
}

let scenarioRequest = 0;

async function loadScenario(): Promise<void> {
  if (storeId.value === '') return;

  const request = ++scenarioRequest;
  scenarioLoading.value = true;
  scenarioError.value = null;

  try {
    const found = (await listScenarios(storeId.value))[0] ?? null;

    if (request === scenarioRequest) scenario.value = found;
  } catch (caught) {
    if (request === scenarioRequest) scenarioError.value = caught;
  } finally {
    if (request === scenarioRequest) scenarioLoading.value = false;
  }
}

async function toggleBrowser(): Promise<void> {
  if (store.value === null) return;

  toggling.value = true;
  toggleError.value = null;

  try {
    replaceStore(await updateStore(store.value, { browser_enabled: !store.value.browser_enabled }));
  } catch (caught) {
    toggleError.value = caught;

    if (caught instanceof ApiError && caught.status === 409) {
      await refreshStore().catch(() => undefined);
    }
  } finally {
    toggling.value = false;
  }
}

async function onScenarioSaved(saved: CheckScenario): Promise<void> {
  scenario.value = saved;
  await refreshStore().catch(() => undefined);
}

listStores()
  .then((value) => {
    stores.value = value;
  })
  .catch((caught: unknown) => {
    error.value = caught;
  })
  .finally(() => {
    loading.value = false;
  });

watch(storeId, () => loadScenario(), { immediate: true });
</script>

<template>
  <AppLayout>
    <div class="flex flex-col gap-6">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('checks.title') }}</h1>
          <p class="max-w-3xl text-sm text-text-muted">{{ $t('checks.subtitle') }}</p>
        </div>
        <label v-if="stores.length > 1" class="flex flex-col gap-1 text-sm font-medium">
          {{ $t('incidents.filter.store') }}
          <select :value="storeId" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-normal" @change="router.replace({ query: { store: ($event.target as HTMLSelectElement).value } })">
            <option v-for="item in stores" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </label>
      </div>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />
      <p v-else-if="stores.length === 0" class="rounded-xl border border-dashed border-border-strong bg-surface p-6 text-sm text-text-muted">{{ $t('reconciliation.no_stores') }}</p>

      <template v-else-if="store">
        <section class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-5" aria-labelledby="readiness-title" data-panel="readiness">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="readiness-title" class="text-lg font-semibold">{{ $t('checks.readiness.title') }}</h2>
            <StatusBadge :tone="ready ? 'ok' : 'unknown'" :label="ready ? $t('checks.readiness.ready') : $t('checks.readiness.not_ready')" />
          </div>
          <ul class="flex flex-col gap-2">
            <li v-for="condition in conditions" :key="condition.key" class="flex flex-wrap items-center justify-between gap-2 text-sm" :data-condition="condition.key" :data-met="condition.met">
              <span class="flex items-center gap-2">
                <span class="size-2 rounded-full" :class="condition.met ? 'bg-ok-solid' : 'bg-unknown-solid'" aria-hidden="true"></span>
                {{ $t(`checks.readiness.${condition.key}.${condition.met ? 'met' : 'missing'}`) }}
              </span>
              <RouterLink v-if="!condition.met && (condition.key === 'verified' || condition.key === 'active')" :to="{ name: 'store', params: { id: store.id } }" class="text-primary hover:underline">
                {{ $t('checks.readiness.open_store') }}
              </RouterLink>
              <button
                v-if="condition.key === 'browser_enabled' && canManageStores"
                type="button"
                class="rounded-md border border-border-strong bg-surface px-3 py-1 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
                :disabled="toggling || (!store.browser_enabled && store.verified_at === null)"
                @click="toggleBrowser"
              >
                {{ store.browser_enabled ? $t('checks.readiness.browser_enabled.turn_off') : $t('checks.readiness.browser_enabled.turn_on') }}
              </button>
            </li>
          </ul>
          <ErrorNotice v-if="toggleError" :error="toggleError" />
          <p class="text-[13px] text-text-muted">{{ $t('checks.readiness.note') }}</p>
        </section>

        <p v-if="scenarioLoading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
        <ErrorNotice v-else-if="scenarioError" :error="scenarioError" />
        <template v-else>
          <ScenarioPanel :key="store.id" :store="store" :scenario="scenario" :can-manage="canManageStores" @saved="onScenarioSaved" @stale="loadScenario" />
          <RunsList :key="`runs-${store.id}`" :store-id="store.id" :scenario="scenario" :can-run="canManageStores" :ready="ready" @finished="refreshStore" />
        </template>
      </template>
    </div>
  </AppLayout>
</template>
