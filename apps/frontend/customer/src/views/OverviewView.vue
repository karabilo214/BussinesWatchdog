<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { getOverview } from '@/api/overview';
import { listStores } from '@/api/stores';
import type { Overview, Store } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import StoreCard from '@/components/StoreCard.vue';
import { RUN_TONES } from '@/components/checks/tones';
import IncidentListItem from '@/components/incidents/IncidentListItem.vue';
import { useIncidentText } from '@/components/incidents/useIncidentText';
import { lastSuccessfulCheck, verdict, type Verdict } from '@/components/overview/verdict';
import { useFormat } from '@/composables/useFormat';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';
import { StatusBadge, type Tone } from '@bw/ui';

const { canManageStores } = useRole();
const { dateTime } = useFormat();
const { amount } = useIncidentText();

const VERDICT_TONES: Record<Verdict, Tone> = { no_stores: 'unknown', problems: 'crit', incomplete: 'unknown', all_clear: 'ok' };
const VERDICT_CLASSES: Record<Tone, string> = {
  ok: 'border-ok-border bg-ok-soft',
  crit: 'border-crit-border bg-crit-soft',
  unknown: 'border-unknown-border bg-unknown-soft',
  warn: 'border-warn-border bg-warn-soft',
  note: 'border-note-border bg-note-soft',
};

const stores = ref<Store[]>([]);
const overview = ref<Overview | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);

const state = computed(() => verdict(stores.value, overview.value?.incidents.active ?? 0));
const storeNames = computed(() => new Map(stores.value.map((store) => [store.id, store.name])));
const lastCheck = computed(() => lastSuccessfulCheck(stores.value));
const uncovered = computed(() => stores.value.filter((store) => store.coverage.connector.state !== 'fresh' || store.coverage.money.state !== 'reconciling' || store.coverage.browser_checks.state !== 'passing').length);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;

  try {
    [stores.value, overview.value] = await Promise.all([listStores(), getOverview()]);
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

function componentLabel(component: string): string {
  return `overview.component.${component}`;
}

onMounted(load);
</script>

<template>
  <AppLayout>
    <div class="flex flex-col gap-6">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('overview.title') }}</h1>
          <p class="max-w-3xl text-sm text-text-muted">{{ $t('overview.subtitle') }}</p>
        </div>
        <RouterLink
          v-if="canManageStores"
          :to="{ name: 'store-create' }"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover"
        >
          {{ $t('overview.add_store') }}
        </RouterLink>
      </div>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>

      <ErrorNotice v-else-if="error" :error="error">
        <button type="button" class="mt-2 self-start rounded-md bg-primary px-3 py-1.5 font-medium text-text-inverse hover:bg-primary-hover" @click="load">
          {{ $t('common.retry') }}
        </button>
      </ErrorNotice>

      <div v-else-if="stores.length === 0" class="flex flex-col gap-1 rounded-xl border border-dashed border-border-strong bg-surface p-8">
        <h2 class="text-lg font-semibold">{{ $t('overview.empty_title') }}</h2>
        <p class="max-w-2xl text-sm text-text-muted">{{ $t('overview.empty_body') }}</p>
      </div>

      <template v-else-if="overview">
        <section class="flex flex-col gap-1 rounded-xl border p-5" :class="VERDICT_CLASSES[VERDICT_TONES[state]]" :data-verdict="state" aria-live="polite">
          <div class="flex flex-wrap items-center gap-2">
            <StatusBadge :tone="VERDICT_TONES[state]" solid :label="$t(`overview.verdict.${state}.badge`)" />
            <h2 class="text-lg font-semibold">{{ $t(`overview.verdict.${state}.title`, { n: overview.incidents.active, stores: uncovered }) }}</h2>
          </div>
          <p class="text-sm">{{ $t(`overview.verdict.${state}.body`) }}</p>
        </section>

        <div class="grid gap-4 md:grid-cols-3">
          <RouterLink :to="{ name: 'incidents' }" class="flex flex-col gap-2 rounded-xl border border-border bg-surface p-5 hover:border-border-strong" data-tile="incidents">
            <span class="text-sm text-text-muted">{{ $t('overview.tiles.incidents') }}</span>
            <span class="text-3xl font-semibold">{{ overview.incidents.active }}</span>
            <span class="flex flex-wrap gap-1.5">
              <StatusBadge v-if="overview.incidents.by_severity.critical" tone="crit" :label="$t('overview.tiles.critical', { n: overview.incidents.by_severity.critical })" />
              <StatusBadge v-if="overview.incidents.by_severity.warning" tone="warn" :label="$t('overview.tiles.warning', { n: overview.incidents.by_severity.warning })" />
              <StatusBadge v-if="overview.incidents.by_severity.info" tone="note" :label="$t('overview.tiles.info', { n: overview.incidents.by_severity.info })" />
            </span>
          </RouterLink>

          <RouterLink :to="{ name: 'reconciliation' }" class="flex flex-col gap-2 rounded-xl border border-border bg-surface p-5 hover:border-border-strong" data-tile="discrepancies">
            <span class="text-sm text-text-muted">{{ $t('overview.tiles.discrepancies') }}</span>
            <span v-if="overview.discrepancies.length === 0 && overview.unknown_amount_incidents === 0" class="text-sm">{{ $t('overview.tiles.no_discrepancies') }}</span>
            <ul v-else class="flex flex-col gap-1 text-sm">
              <li v-for="row in overview.discrepancies" :key="`${row.currency}-${row.component}`" class="flex flex-wrap items-baseline justify-between gap-x-3" :data-discrepancy="`${row.currency}-${row.component}`">
                <span class="text-text-muted">{{ $te(componentLabel(row.component)) ? $t(componentLabel(row.component)) : row.component }}</span>
                <span class="font-mono font-semibold">{{ amount(row.total_minor, row.currency, row.currency_exponent) ?? `${row.total_minor} ${row.currency} (${$t('overview.tiles.minor_units')})` }}</span>
              </li>
              <li v-if="overview.unknown_amount_incidents" class="text-[13px] text-text-muted">{{ $t('overview.tiles.unknown_amounts', { n: overview.unknown_amount_incidents }) }}</li>
            </ul>
            <span class="text-[13px] text-text-muted">{{ $t('overview.tiles.discrepancies_note') }}</span>
          </RouterLink>

          <RouterLink :to="{ name: 'checks' }" class="flex flex-col gap-2 rounded-xl border border-border bg-surface p-5 hover:border-border-strong" data-tile="checks">
            <span class="text-sm text-text-muted">{{ $t('overview.tiles.last_check') }}</span>
            <span class="text-lg font-semibold">{{ lastCheck ? dateTime(lastCheck) : $t('common.never') }}</span>
            <span class="text-[13px] text-text-muted">{{ uncovered > 0 ? $t('overview.tiles.uncovered', { n: uncovered }) : $t('overview.tiles.all_covered') }}</span>
          </RouterLink>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
          <section class="flex flex-col gap-3" aria-labelledby="latest-incidents">
            <h2 id="latest-incidents" class="text-lg font-semibold">{{ $t('overview.latest_incidents') }}</h2>
            <p v-if="overview.incidents.latest.length === 0" class="rounded-xl border border-dashed border-border-strong bg-surface p-5 text-sm text-text-muted">{{ $t('overview.no_active_incidents') }}</p>
            <ul v-else class="divide-y divide-border rounded-xl border border-border bg-surface">
              <IncidentListItem v-for="incident in overview.incidents.latest" :key="incident.id" :incident="incident" :store-name="storeNames.get(incident.store_id) ?? null" />
            </ul>
          </section>

          <section class="flex flex-col gap-3" aria-labelledby="recent-checks">
            <h2 id="recent-checks" class="text-lg font-semibold">{{ $t('overview.recent_checks') }}</h2>
            <p v-if="overview.recent_checks.length === 0" class="rounded-xl border border-dashed border-border-strong bg-surface p-5 text-sm text-text-muted">{{ $t('checks.runs.empty') }}</p>
            <ul v-else class="divide-y divide-border rounded-xl border border-border bg-surface">
              <li v-for="run in overview.recent_checks" :key="run.id" class="flex flex-wrap items-center justify-between gap-3 p-4 text-sm" :data-run-id="run.id">
                <div class="flex flex-col gap-0.5">
                  <RouterLink :to="{ name: 'check-run', params: { id: run.id } }" class="font-medium hover:text-primary hover:underline">{{ storeNames.get(run.store_id) ?? '—' }}</RouterLink>
                  <span class="text-[13px] text-text-muted">{{ dateTime(run.finished_at) }} · {{ $t(`checks.trigger.${run.trigger}`) }}</span>
                </div>
                <StatusBadge :tone="RUN_TONES[run.status]" :label="$t(`checks.status.${run.status}`)" />
              </li>
            </ul>
          </section>
        </div>

        <section class="flex flex-col gap-3" aria-labelledby="stores-title">
          <h2 id="stores-title" class="text-lg font-semibold">{{ $t('overview.stores') }}</h2>
          <div class="grid gap-4 lg:grid-cols-2">
            <StoreCard v-for="store in stores" :key="store.id" :store="store" />
          </div>
        </section>
      </template>
    </div>
  </AppLayout>
</template>
