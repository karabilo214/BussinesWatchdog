<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { formatMinor, StatusBadge, type Tone } from '@bw/ui';
import { fetchDiscountApplications, type DiscountApplication } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import PlansTabs from '@/components/PlansTabs.vue';
import { useFormat } from '@/composables/useFormat';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const STATUS_TONES: Record<DiscountApplication['status'], Tone> = { planned: 'note', applied: 'ok', failed: 'crit' };

const { locale } = useI18n();
const { dateTime } = useFormat();
const rows = ref<DiscountApplication[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);

function money(row: DiscountApplication, minor: string): string {
  return formatMinor(minor, row.currency, row.currency_exponent, locale.value) ?? `${minor} ${row.currency}`;
}

async function load(more = false): Promise<void> {
  try {
    const page = await fetchDiscountApplications(more ? (cursor.value ?? undefined) : undefined);
    rows.value = more ? [...rows.value, ...page.data] : page.data;
    cursor.value = page.next_cursor;
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

onMounted(() => load());
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-5xl flex-col gap-6">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('section.plans.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('history.hint') }}</p>
      </div>
      <PlansTabs />

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />
      <p v-else-if="rows.length === 0" class="rounded-xl border border-border bg-surface p-5 text-sm text-text-muted">{{ $t('history.empty') }}</p>

      <ul v-else class="flex flex-col gap-3">
        <li v-for="row in rows" :key="row.id" class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-4" :data-application="row.period_number">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-semibold">{{ row.tenant.name }}</span>
            <span class="text-sm text-text-muted">{{ row.period_number === 1 ? $t('history.purchase') : $t('history.renewal', { n: row.period_number }) }}</span>
            <span v-if="row.period_start" class="text-sm text-text-muted">· {{ $t('history.from', { date: dateTime(row.period_start) }) }}</span>
            <StatusBadge :tone="STATUS_TONES[row.status]" :label="$t(`history.status.${row.status}`)" />
          </div>
          <table class="w-full text-sm">
            <tbody class="divide-y divide-border">
              <tr>
                <td class="py-1.5 text-text-muted">{{ $t('history.before') }}</td>
                <td class="py-1.5 text-right tabular-nums">{{ money(row, row.amount_before_minor) }}</td>
              </tr>
              <tr v-for="line in row.lines" :key="line.position" :data-line="line.discount?.audience">
                <td class="py-1.5">
                  {{ $t(`history.kind.${line.discount?.audience ?? 'unknown'}`) }}<template v-if="line.discount">: {{ line.discount.name }}<span v-if="line.discount.code" class="font-mono"> ({{ line.discount.code }})</span></template>
                  <span class="text-text-muted"> · {{ $t('history.of', { amount: money(row, line.amount_before_minor) }) }}</span>
                </td>
                <td class="py-1.5 text-right tabular-nums text-ok">−{{ money(row, line.saving_minor) }}</td>
              </tr>
              <tr class="font-semibold">
                <td class="py-1.5">{{ $t('history.after', { saving: money(row, row.saving_minor) }) }}</td>
                <td class="py-1.5 text-right tabular-nums">{{ money(row, row.amount_after_minor) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="row.error_code" class="text-[13px] text-crit">{{ $t('history.failed', { code: row.error_code }) }}</p>
        </li>
      </ul>
      <button v-if="cursor" type="button" class="self-start rounded-md border border-border-strong px-4 py-2 text-sm font-medium text-primary" @click="load(true)">{{ $t('history.more') }}</button>
    </div>
  </OwnerLayout>
</template>
