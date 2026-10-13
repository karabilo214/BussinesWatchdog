<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { StatusBadge, formatMinor } from '@bw/ui';
import { fetchOverview, type BusinessOverview, type OverviewMoney } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const { locale } = useI18n();
const { dateTime } = useFormat();
const { can } = useStaff();

const allowed = computed(() => can('billing.read'));
const data = ref<BusinessOverview | null>(null);
const loading = ref(false);
const error = ref<unknown>(null);
const filters = reactive({ mode: 'live' as 'live' | 'test', from: '', to: '' });

function day(iso: string, shift = 0): string {
  const date = new Date(iso);
  date.setUTCDate(date.getUTCDate() + shift);

  return date.toISOString().slice(0, 10);
}

function money(minor: string, currency: string, exponent: number): string {
  return formatMinor(minor, currency, exponent, locale.value) ?? `${minor} ${currency}`;
}

function rounded(numerator: string, denominator: string): string {
  const top = BigInt(numerator);
  const bottom = BigInt(denominator);
  const half = (top * 2n + (top < 0n ? -bottom : bottom)) / (2n * bottom);

  return half.toString();
}

function previous(currency: string): OverviewMoney | undefined {
  return data.value?.money_previous.find((row) => row.currency === currency);
}

function percent(part: number, whole: number): string {
  return whole === 0 ? '—' : new Intl.NumberFormat(locale.value, { style: 'percent', maximumFractionDigits: 1 }).format(part / whole);
}

async function load(): Promise<void> {
  if (!allowed.value) return;
  loading.value = true;
  error.value = null;

  try {
    data.value = await fetchOverview({ mode: filters.mode, ...(filters.from ? { from: filters.from } : {}), ...(filters.to ? { to: filters.to } : {}) });
    filters.from = day(data.value.from);
    filters.to = day(data.value.to, -1);
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-6xl flex-col gap-6">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('section.overview.title') }}</h1>
        <p class="text-sm text-text-muted">{{ allowed ? $t('overview.hint') : $t('section.overview.body') }}</p>
      </div>

      <template v-if="allowed">
        <form class="flex flex-wrap items-end gap-3 rounded-xl border border-border bg-surface p-4 text-sm" data-form="overview" @submit.prevent="load">
          <div class="flex rounded-md border border-border-strong" role="group" :aria-label="$t('overview.mode')">
            <button v-for="mode in (['live', 'test'] as const)" :key="mode" type="button" class="px-3 py-2 font-medium first:rounded-l-md last:rounded-r-md" :class="filters.mode === mode ? 'bg-primary text-text-inverse' : 'text-primary hover:bg-primary-soft'" :aria-pressed="filters.mode === mode" :data-mode="mode" @click="filters.mode = mode; load()">{{ mode.toUpperCase() }}</button>
          </div>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('overview.from') }}</span>
            <input v-model="filters.from" type="date" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="from" />
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('overview.to') }}</span>
            <input v-model="filters.to" type="date" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="to" />
          </label>
          <button type="submit" class="rounded-md border border-border-strong px-4 py-2 font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="loading" data-action="apply">{{ $t('overview.apply') }}</button>
          <span class="text-text-muted">{{ $t('overview.utc') }}</span>
        </form>

        <ErrorNotice v-if="error" :error="error" />
        <p v-if="loading && !data" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>

        <template v-if="data">
          <p v-if="data.last_synced_at === null" class="rounded-md border border-warn-border bg-warn-soft px-3 py-2 text-sm text-warn" data-incomplete>{{ $t('overview.no_data', { mode: data.mode.toUpperCase() }) }}</p>
          <p v-else class="text-sm text-text-muted" data-synced>{{ $t('overview.synced', { date: dateTime(data.last_synced_at) }) }}</p>

          <section class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4" data-section="customers">
            <RouterLink v-for="item in ([
              { key: 'paying', query: { status: 'active' } },
              { key: 'trial', query: { status: 'trial' } },
              { key: 'past_due', query: { status: 'past_due' } },
              { key: 'cancel_scheduled', query: { cancel_scheduled: '1' } },
            ] as const)" :key="item.key" :to="{ name: 'owner-subscriptions', query: item.query }" class="flex flex-col gap-1 rounded-xl border border-border bg-surface p-4 hover:border-primary" :data-kpi="item.key">
              <span class="text-sm text-text-muted">{{ $t(`overview.customers.${item.key}`) }}</span>
              <span class="text-2xl font-semibold tabular-nums">{{ data.customers[item.key] }}</span>
            </RouterLink>
          </section>

          <section class="flex flex-col gap-3" data-section="mrr">
            <div class="flex flex-col gap-1">
              <h2 class="text-lg font-semibold">{{ $t('overview.mrr.title') }}</h2>
              <p class="text-sm text-text-muted">{{ $t('overview.mrr.definition') }}</p>
            </div>
            <p v-if="data.mrr.length === 0" class="text-sm text-text-muted">{{ $t('overview.mrr.none') }}</p>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <article v-for="row in data.mrr" :key="row.currency" class="flex flex-col gap-1 rounded-xl border border-border bg-surface p-4" :data-mrr="row.currency">
                <span class="text-sm text-text-muted">{{ row.currency }}</span>
                <span class="text-2xl font-semibold tabular-nums" :title="`${row.numerator_minor}/${row.denominator}`"><template v-if="row.denominator !== '1'">≈ </template>{{ money(rounded(row.numerator_minor, row.denominator), row.currency, row.currency_exponent) }}</span>
                <span class="text-sm text-text-muted">{{ $t('overview.mrr.contracts', { n: row.contracts }) }}</span>
                <StatusBadge v-if="row.discounted_contracts > 0" tone="note" :label="$t('overview.mrr.discounted', { n: row.discounted_contracts })" class="self-start" />
                <StatusBadge v-if="row.unpriced_contracts > 0" tone="warn" :label="$t('overview.mrr.unpriced', { n: row.unpriced_contracts })" class="self-start" />
              </article>
            </div>
          </section>

          <section class="flex flex-col gap-3" data-section="money">
            <div class="flex flex-col gap-1">
              <h2 class="text-lg font-semibold">{{ $t('overview.money.title') }}</h2>
              <p class="text-sm text-text-muted">{{ $t('overview.money.definition') }}</p>
            </div>
            <p v-if="data.money.length === 0" class="text-sm text-text-muted">{{ $t('overview.money.none') }}</p>
            <div v-else class="overflow-x-auto rounded-xl border border-border bg-surface">
              <table class="w-full min-w-[40rem] text-sm">
                <thead class="border-b border-border text-left text-text-muted">
                  <tr>
                    <th class="px-4 py-3 font-medium">{{ $t('overview.money.currency') }}</th>
                    <th class="px-4 py-3 font-medium">{{ $t('overview.money.collected') }}</th>
                    <th class="px-4 py-3 font-medium">{{ $t('overview.money.refunded') }}</th>
                    <th class="px-4 py-3 font-medium">{{ $t('overview.money.net') }}</th>
                    <th class="px-4 py-3 font-medium">{{ $t('overview.money.previous') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-border">
                  <tr v-for="row in data.money" :key="row.currency" :data-money="row.currency">
                    <td class="px-4 py-3 font-medium">{{ row.currency }}</td>
                    <td class="px-4 py-3 tabular-nums">
                      <RouterLink :to="{ name: 'owner-payments' }" class="text-primary hover:underline">{{ money(row.collected_minor, row.currency, row.currency_exponent) }}</RouterLink>
                      <span class="block text-[13px] text-text-muted">{{ $t('overview.money.operations', { n: row.collections }) }} · {{ $t('overview.money.tax', { amount: money(row.collected_tax_minor, row.currency, row.currency_exponent) }) }}</span>
                    </td>
                    <td class="px-4 py-3 tabular-nums">{{ money(row.refunded_minor, row.currency, row.currency_exponent) }}<span class="block text-[13px] text-text-muted">{{ $t('overview.money.operations', { n: row.refunds }) }}</span></td>
                    <td class="px-4 py-3 font-semibold tabular-nums" :class="row.net_minor.startsWith('-') ? 'text-crit' : ''">{{ money(row.net_minor, row.currency, row.currency_exponent) }}</td>
                    <td class="px-4 py-3 tabular-nums text-text-muted">{{ previous(row.currency) ? money(previous(row.currency)!.net_minor, row.currency, row.currency_exponent) : '—' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <section class="grid gap-3 sm:grid-cols-2" data-section="churn">
            <article class="flex flex-col gap-1 rounded-xl border border-border bg-surface p-4" data-kpi="churn">
              <span class="text-sm text-text-muted">{{ $t('overview.churn.title') }}</span>
              <span class="text-2xl font-semibold tabular-nums">{{ percent(data.churn.ended, data.churn.at_start) }}</span>
              <span class="text-sm text-text-muted">{{ $t('overview.churn.counts', { ended: data.churn.ended, start: data.churn.at_start }) }}</span>
              <span class="text-sm text-text-muted">{{ $t('overview.churn.previous', { value: percent(data.churn_previous.ended, data.churn_previous.at_start), ended: data.churn_previous.ended, start: data.churn_previous.at_start }) }}</span>
            </article>
            <article class="flex flex-col gap-1 rounded-xl border border-dashed border-border-strong bg-surface p-4 text-sm text-text-muted" data-kpi="later">
              <span class="font-medium text-text">{{ $t('overview.later.title') }}</span>
              <span>{{ $t('overview.later.body') }}</span>
            </article>
          </section>
          <p class="text-xs text-text-muted">{{ $t('overview.as_of', { date: dateTime(data.as_of) }) }}</p>
        </template>
      </template>
    </div>
  </OwnerLayout>
</template>
