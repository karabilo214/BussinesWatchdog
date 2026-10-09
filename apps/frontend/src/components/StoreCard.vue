<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Store } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import StatusBadge from './StatusBadge.vue';
import { BROWSER_CHECK_TONES, CONNECTOR_TONES, MONEY_TONES, PAYMENT_ATTEMPTS_TONES, type Tone } from './tones';

const props = defineProps<{ store: Store }>();
const { t } = useI18n();
const { dateTime } = useFormat();

interface Row {
  key: string;
  label: string;
  tone: Tone;
  state: string;
  detail: string | null;
}

const rows = computed<Row[]>(() => {
  const coverage = props.store.coverage;

  return [
    {
      key: 'connector',
      label: t('coverage.connector.label'),
      tone: CONNECTOR_TONES[coverage.connector.state],
      state: t(`coverage.connector.${coverage.connector.state}`),
      detail: coverage.connector.last_heartbeat_at ? t('coverage.connector.detail_heartbeat', { time: dateTime(coverage.connector.last_heartbeat_at) }) : null,
    },
    {
      key: 'money',
      label: t('coverage.money.label'),
      tone: MONEY_TONES[coverage.money.state],
      state: t(`coverage.money.${coverage.money.state}`),
      detail: coverage.money.state === 'provider_not_connected' ? t('coverage.money.detail_provider') : null,
    },
    {
      key: 'payment_attempts',
      label: t('coverage.payment_attempts.label'),
      tone: PAYMENT_ATTEMPTS_TONES[coverage.payment_attempts.state],
      state: t(`coverage.payment_attempts.${coverage.payment_attempts.state}`),
      detail: coverage.payment_attempts.last_window_end_at ? t('coverage.payment_attempts.detail_window', { time: dateTime(coverage.payment_attempts.last_window_end_at) }) : null,
    },
    {
      key: 'browser_checks',
      label: t('coverage.browser_checks.label'),
      tone: BROWSER_CHECK_TONES[coverage.browser_checks.state],
      state: t(`coverage.browser_checks.${coverage.browser_checks.state}`),
      detail: coverage.browser_checks.next_due_at ? t('coverage.browser_checks.detail_next', { time: dateTime(coverage.browser_checks.next_due_at) }) : null,
    },
  ];
});

const host = computed(() => {
  try {
    return new URL(props.store.base_url).host;
  } catch {
    return props.store.base_url;
  }
});
</script>

<template>
  <article class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-5" :data-store-id="store.id">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex min-w-0 flex-col gap-0.5">
        <h2 class="truncate text-lg font-semibold">{{ store.name }}</h2>
        <p class="truncate text-sm text-text-muted">{{ host }} · {{ $t(`store_status.${store.status}`) }}</p>
      </div>
      <StatusBadge
        v-if="store.active_incident_count > 0"
        tone="crit"
        solid
        :label="$t('overview.active_incidents', { n: store.active_incident_count })"
      />
      <StatusBadge v-else tone="ok" :label="$t('overview.no_incidents')" />
    </header>

    <dl class="flex flex-col gap-3">
      <div v-for="row in rows" :key="row.key" class="grid gap-1 sm:grid-cols-[11rem_1fr] sm:gap-3" :data-coverage="row.key">
        <dt class="pt-0.5 text-sm text-text-muted">{{ row.label }}</dt>
        <dd class="flex flex-col items-start gap-1">
          <StatusBadge :tone="row.tone" :label="row.state" />
          <span v-if="row.detail" class="text-[13px] text-text-muted">{{ row.detail }}</span>
        </dd>
      </div>
    </dl>

    <p class="border-t border-border pt-3 text-[13px] text-text-muted">
      {{ $t('overview.last_passed_check', { time: dateTime(store.last_successful_check_at) }) }}
    </p>
  </article>
</template>
