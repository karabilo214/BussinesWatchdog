<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { listIncidents } from '@/api/incidents';
import { listStores } from '@/api/stores';
import type { Incident, IncidentSeverity, IncidentState, Store } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import IncidentListItem from '@/components/incidents/IncidentListItem.vue';
import AppLayout from '@/layouts/AppLayout.vue';

type Tab = 'active' | 'resolved';

const STATES: Record<Tab, IncidentState[]> = { active: ['open', 'acknowledged'], resolved: ['resolved'] };
const SEVERITIES: IncidentSeverity[] = ['critical', 'warning', 'info'];

const route = useRoute();
const router = useRouter();

const stores = ref<Store[]>([]);
const incidents = ref<Incident[]>([]);
const nextCursor = ref<string | null>(null);
const loading = ref(true);
const loadingMore = ref(false);
const error = ref<unknown>(null);

const tab = computed<Tab>(() => (route.query.tab === 'resolved' ? 'resolved' : 'active'));
const storeId = computed(() => (typeof route.query.store === 'string' ? route.query.store : ''));
const severity = computed(() => (SEVERITIES.includes(route.query.severity as IncidentSeverity) ? (route.query.severity as IncidentSeverity) : ''));
const storeNames = computed(() => new Map(stores.value.map((store) => [store.id, store.name])));

function setQuery(changes: Record<string, string>): void {
  const query = { ...route.query, ...changes };

  for (const key of Object.keys(query)) {
    if (query[key] === '' || (key === 'tab' && query[key] === 'active')) {
      delete query[key];
    }
  }

  void router.replace({ query });
}

async function load(more = false): Promise<void> {
  if (more) {
    loadingMore.value = true;
  } else {
    loading.value = true;
  }

  error.value = null;

  try {
    const page = await listIncidents({
      state: STATES[tab.value],
      store_id: storeId.value || undefined,
      severity: severity.value || undefined,
      cursor: more ? (nextCursor.value ?? undefined) : undefined,
      limit: 50,
    });
    incidents.value = more ? [...incidents.value, ...page.data] : page.data;
    nextCursor.value = page.next_cursor;
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
    loadingMore.value = false;
  }
}

listStores()
  .then((value) => {
    stores.value = value;
  })
  .catch(() => undefined);

watch([tab, storeId, severity], () => load(), { immediate: true });
</script>

<template>
  <AppLayout>
    <div class="flex flex-col gap-6">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('incidents.title') }}</h1>
        <p class="max-w-3xl text-sm text-text-muted">{{ $t('incidents.subtitle') }}</p>
      </div>

      <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex gap-1 rounded-lg border border-border bg-surface p-1" role="tablist">
          <button
            v-for="value in (['active', 'resolved'] as Tab[])"
            :key="value"
            type="button"
            role="tab"
            :aria-selected="tab === value"
            class="rounded-md px-3 py-1.5 text-sm font-medium"
            :class="tab === value ? 'bg-primary-soft text-primary' : 'text-text-muted hover:bg-surface-muted hover:text-text'"
            @click="setQuery({ tab: value })"
          >
            {{ $t(`incidents.tab.${value}`) }}
          </button>
        </div>
        <div class="flex flex-wrap gap-3">
          <label class="flex flex-col gap-1 text-sm font-medium">
            {{ $t('incidents.filter.store') }}
            <select
              :value="storeId"
              class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-normal"
              @change="setQuery({ store: ($event.target as HTMLSelectElement).value })"
            >
              <option value="">{{ $t('incidents.filter.all_stores') }}</option>
              <option v-for="store in stores" :key="store.id" :value="store.id">{{ store.name }}</option>
            </select>
          </label>
          <label class="flex flex-col gap-1 text-sm font-medium">
            {{ $t('incidents.filter.severity') }}
            <select
              :value="severity"
              class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-normal"
              @change="setQuery({ severity: ($event.target as HTMLSelectElement).value })"
            >
              <option value="">{{ $t('incidents.filter.all_severities') }}</option>
              <option v-for="value in SEVERITIES" :key="value" :value="value">{{ $t(`incident.severity.${value}`) }}</option>
            </select>
          </label>
        </div>
      </div>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>

      <ErrorNotice v-else-if="error && incidents.length === 0" :error="error">
        <button type="button" class="mt-2 self-start rounded-md bg-primary px-3 py-1.5 font-medium text-text-inverse hover:bg-primary-hover" @click="load()">
          {{ $t('common.retry') }}
        </button>
      </ErrorNotice>

      <div v-else-if="incidents.length === 0" class="flex flex-col gap-1 rounded-xl border border-dashed border-border-strong bg-surface p-8">
        <h2 class="text-lg font-semibold">{{ $t(`incidents.empty.${tab}`) }}</h2>
        <p v-if="tab === 'active'" class="max-w-2xl text-sm text-text-muted">{{ $t('incidents.empty.active_note') }}</p>
      </div>

      <template v-else>
        <ul class="divide-y divide-border rounded-xl border border-border bg-surface">
          <IncidentListItem v-for="incident in incidents" :key="incident.id" :incident="incident" :store-name="storeNames.get(incident.store_id) ?? null" />
        </ul>
        <ErrorNotice v-if="error" :error="error" />
        <div v-if="nextCursor">
          <button
            type="button"
            class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
            :disabled="loadingMore"
            @click="load(true)"
          >
            {{ $t('incidents.load_more') }}
          </button>
        </div>
      </template>
    </div>
  </AppLayout>
</template>
