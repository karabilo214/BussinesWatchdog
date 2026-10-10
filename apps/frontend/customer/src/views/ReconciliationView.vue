<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { recheckMoney } from '@/api/incidents';
import { listFindings } from '@/api/reconciliation';
import { listStores } from '@/api/stores';
import type { Finding, FindingStatus, Store } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import FindingsTable from '@/components/reconciliation/FindingsTable.vue';
import { FINDING_STATUSES, RULE_CODES } from '@/components/reconciliation/tones';
import UnmatchedPayments from '@/components/reconciliation/UnmatchedPayments.vue';
import { currencies } from '@/components/storeOptions';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';

type Tab = 'findings' | 'unmatched';

const route = useRoute();
const router = useRouter();
const { canManageStores, canHandleIncidents } = useRole();

const stores = ref<Store[]>([]);
const storesLoading = ref(true);
const storesError = ref<unknown>(null);
const findings = ref<Finding[]>([]);
const nextCursor = ref<string | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);
const scanning = ref(false);
const scanError = ref<unknown>(null);
const scanDone = ref(false);
const unmatchedKey = ref(0);

function param(name: string): string {
  return typeof route.query[name] === 'string' ? (route.query[name] as string) : '';
}

const storeId = computed(() => param('store') || stores.value[0]?.id || '');
const store = computed(() => stores.value.find((item) => item.id === storeId.value) ?? null);
const tab = computed<Tab>(() => (param('tab') === 'unmatched' ? 'unmatched' : 'findings'));
const history = computed(() => param('view') === 'history');
const status = computed<FindingStatus | ''>(() => {
  const value = param('status');

  if (value === 'all') return '';

  return FINDING_STATUSES.includes(value as FindingStatus) ? (value as FindingStatus) : 'mismatch';
});
const statusParam = computed(() => (param('status') === 'all' ? 'all' : status.value));
const rule = computed(() => (RULE_CODES.includes(param('rule')) ? param('rule') : ''));
const currency = computed(() => (/^[A-Z]{3}$/.test(param('currency')) ? param('currency') : ''));
const from = computed(() => (/^\d{4}-\d{2}-\d{2}$/.test(param('from')) ? param('from') : ''));
const to = computed(() => (/^\d{4}-\d{2}-\d{2}$/.test(param('to')) ? param('to') : ''));
const currencyOptions = computed(() => currencies(...stores.value.map((item) => item.default_currency)));
const moneyCovered = computed(() => store.value?.coverage.money.state === 'reconciling');

function setQuery(changes: Record<string, string>): void {
  const query: Record<string, string> = {};

  for (const [key, value] of Object.entries({ ...route.query, ...changes })) {
    if (typeof value === 'string' && value !== '') query[key] = value;
  }

  void router.replace({ query });
}

/** A calendar day in the viewer's time zone, as an RFC 3339 instant. */
function dayStart(day: string, offsetDays = 0): string {
  const date = new Date(`${day}T00:00:00`);
  date.setDate(date.getDate() + offsetDays);

  return date.toISOString();
}

async function loadFindings(more = false): Promise<void> {
  if (storeId.value === '' || tab.value !== 'findings') return;

  loading.value = !more;
  error.value = null;

  try {
    const page = await listFindings(storeId.value, {
      current: !history.value,
      status: status.value || undefined,
      rule_code: rule.value || undefined,
      currency: currency.value || undefined,
      from: from.value ? dayStart(from.value) : undefined,
      to: to.value ? dayStart(to.value, 1) : undefined,
      cursor: more ? (nextCursor.value ?? undefined) : undefined,
    });
    findings.value = more ? [...findings.value, ...page.data] : page.data;
    nextCursor.value = page.next_cursor;
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function scan(): Promise<void> {
  scanning.value = true;
  scanError.value = null;
  scanDone.value = false;

  try {
    await recheckMoney(storeId.value, []);
    scanDone.value = true;
    unmatchedKey.value++;
    await loadFindings();
  } catch (caught) {
    scanError.value = caught;
  } finally {
    scanning.value = false;
  }
}

listStores()
  .then((value) => {
    stores.value = value;
  })
  .catch((caught: unknown) => {
    storesError.value = caught;
  })
  .finally(() => {
    storesLoading.value = false;
  });

watch([storeId, tab, history, status, rule, currency, from, to], () => loadFindings(), { immediate: true });
</script>

<template>
  <AppLayout>
    <div class="flex flex-col gap-6">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('reconciliation.title') }}</h1>
          <p class="max-w-3xl text-sm text-text-muted">{{ $t('reconciliation.subtitle') }}</p>
        </div>
        <label v-if="stores.length > 1" class="flex flex-col gap-1 text-sm font-medium">
          {{ $t('incidents.filter.store') }}
          <select :value="storeId" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-normal" @change="setQuery({ store: ($event.target as HTMLSelectElement).value })">
            <option v-for="item in stores" :key="item.id" :value="item.id">{{ item.name }}</option>
          </select>
        </label>
      </div>

      <p v-if="storesLoading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="storesError" :error="storesError" />
      <p v-else-if="stores.length === 0" class="rounded-xl border border-dashed border-border-strong bg-surface p-6 text-sm text-text-muted">{{ $t('reconciliation.no_stores') }}</p>

      <template v-else-if="store">
        <div v-if="!moneyCovered" class="flex flex-col gap-1 rounded-xl border border-unknown-border bg-unknown-soft p-4 text-sm" data-coverage-warning>
          <p class="font-medium">{{ $t(`coverage.money.${store.coverage.money.state}`) }}</p>
          <p>{{ $t(`reconciliation.coverage.${store.coverage.money.state}`) }}</p>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <div class="flex gap-1 rounded-lg border border-border bg-surface p-1" role="tablist">
            <button
              v-for="value in (['findings', 'unmatched'] as Tab[])"
              :key="value"
              type="button"
              role="tab"
              :aria-selected="tab === value"
              class="rounded-md px-3 py-1.5 text-sm font-medium"
              :class="tab === value ? 'bg-primary-soft text-primary' : 'text-text-muted hover:bg-surface-muted hover:text-text'"
              @click="setQuery({ tab: value === 'findings' ? '' : value })"
            >
              {{ $t(`reconciliation.tab.${value}`) }}
            </button>
          </div>
          <button
            v-if="canHandleIncidents && moneyCovered"
            type="button"
            class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
            :disabled="scanning"
            @click="scan"
          >
            {{ $t('reconciliation.scan') }}
          </button>
        </div>
        <p v-if="scanDone" class="text-sm text-ok" role="status">{{ $t('reconciliation.scan_done') }}</p>
        <ErrorNotice v-if="scanError" :error="scanError" />

        <template v-if="tab === 'findings'">
          <div class="flex flex-wrap gap-3 rounded-xl border border-border bg-surface p-4" data-filters>
            <label class="flex flex-col gap-1 text-sm font-medium">
              {{ $t('reconciliation.filter.view') }}
              <select :value="history ? 'history' : 'current'" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-normal" @change="setQuery({ view: ($event.target as HTMLSelectElement).value === 'history' ? 'history' : '' })">
                <option value="current">{{ $t('reconciliation.filter.current') }}</option>
                <option value="history">{{ $t('reconciliation.filter.history') }}</option>
              </select>
            </label>
            <label class="flex flex-col gap-1 text-sm font-medium">
              {{ $t('reconciliation.filter.status') }}
              <select :value="statusParam" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-normal" @change="setQuery({ status: ($event.target as HTMLSelectElement).value })">
                <option value="all">{{ $t('reconciliation.filter.all') }}</option>
                <option v-for="value in FINDING_STATUSES" :key="value" :value="value">{{ $t(`reconciliation.status.${value}`) }}</option>
              </select>
            </label>
            <label class="flex flex-col gap-1 text-sm font-medium">
              {{ $t('reconciliation.filter.rule') }}
              <select :value="rule" class="max-w-[16rem] rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-normal" @change="setQuery({ rule: ($event.target as HTMLSelectElement).value })">
                <option value="">{{ $t('reconciliation.filter.all') }}</option>
                <option v-for="code in RULE_CODES" :key="code" :value="code">{{ $t(`reconciliation.rule.${code}`) }}</option>
              </select>
            </label>
            <label class="flex flex-col gap-1 text-sm font-medium">
              {{ $t('store.form.currency') }}
              <select :value="currency" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-normal" @change="setQuery({ currency: ($event.target as HTMLSelectElement).value })">
                <option value="">{{ $t('reconciliation.filter.all') }}</option>
                <option v-for="code in currencyOptions" :key="code" :value="code">{{ code }}</option>
              </select>
            </label>
            <label class="flex flex-col gap-1 text-sm font-medium">
              {{ $t('reconciliation.filter.from') }}
              <input type="date" :value="from" class="rounded-md border border-border-strong bg-surface px-3 py-1 text-sm font-normal" @change="setQuery({ from: ($event.target as HTMLInputElement).value })" />
            </label>
            <label class="flex flex-col gap-1 text-sm font-medium">
              {{ $t('reconciliation.filter.to') }}
              <input type="date" :value="to" class="rounded-md border border-border-strong bg-surface px-3 py-1 text-sm font-normal" @change="setQuery({ to: ($event.target as HTMLInputElement).value })" />
            </label>
          </div>
          <p class="text-[13px] text-text-muted">{{ history ? $t('reconciliation.filter.history_note') : $t('reconciliation.filter.current_note') }}</p>

          <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
          <ErrorNotice v-else-if="error" :error="error">
            <button type="button" class="mt-2 self-start rounded-md bg-primary px-3 py-1.5 font-medium text-text-inverse hover:bg-primary-hover" @click="loadFindings()">{{ $t('common.retry') }}</button>
          </ErrorNotice>
          <p v-else-if="findings.length === 0" class="rounded-xl border border-dashed border-border-strong bg-surface p-6 text-sm text-text-muted" data-empty>
            {{ moneyCovered ? $t('reconciliation.empty') : $t('reconciliation.empty_unknown') }}
          </p>
          <section v-else class="rounded-xl border border-border bg-surface p-5">
            <FindingsTable :findings="findings" />
          </section>
          <div v-if="nextCursor">
            <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium text-primary hover:bg-primary-soft" @click="loadFindings(true)">
              {{ $t('incidents.load_more') }}
            </button>
          </div>
        </template>

        <UnmatchedPayments v-else :key="`${storeId}-${unmatchedKey}`" :store-id="storeId" :can-allocate="canManageStores" />
      </template>
    </div>
  </AppLayout>
</template>
