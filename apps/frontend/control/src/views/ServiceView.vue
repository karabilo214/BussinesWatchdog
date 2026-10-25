<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { StatusBadge } from '@bw/ui';
import { fetchDeadOutbox, fetchServiceHealth, retryDeadOutbox, type DeadOutboxMessage, type HealthState, type ServiceHealth } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const TONES: Record<HealthState, 'ok' | 'warn' | 'crit'> = { ok: 'ok', warn: 'warn', crit: 'crit' };
const QUEUES = ['event_inbox', 'domain_outbox', 'billing_events', 'reconciliation', 'notifications', 'jobs'] as const;

const { locale } = useI18n();
const { dateTime } = useFormat();
const { can } = useStaff();
const allowed = computed(() => can('service.read'));
const canManage = computed(() => can('service.manage'));
const health = ref<ServiceHealth | null>(null);
const deadOutbox = ref<DeadOutboxMessage[]>([]);
const retry = reactive({ target: null as DeadOutboxMessage | null, reason: '', code: '' });
const error = ref<unknown>(null);
const loading = ref(false);
const acting = ref(false);
let timer: ReturnType<typeof setInterval> | null = null;

function age(seconds: number | null): string {
  if (seconds === null) return '—';
  const format = (value: number, unit: 'second' | 'minute' | 'hour' | 'day') => new Intl.NumberFormat(locale.value, { style: 'unit', unit, unitDisplay: 'short', maximumFractionDigits: 0 }).format(value);

  if (seconds < 60) return format(seconds, 'second');
  if (seconds < 3600) return format(Math.floor(seconds / 60), 'minute');
  if (seconds < 86400) return format(Math.floor(seconds / 3600), 'hour');

  return format(Math.floor(seconds / 86400), 'day');
}

async function load(): Promise<void> {
  if (!allowed.value) return;
  loading.value = true;

  try {
    health.value = await fetchServiceHealth();
    if (canManage.value) deadOutbox.value = await fetchDeadOutbox();
    error.value = null;
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function submitRetry(): Promise<void> {
  if (!retry.target || acting.value) return;
  acting.value = true;
  error.value = null;

  try {
    await retryDeadOutbox(retry.target.id, retry.reason.trim(), retry.code.trim());
    retry.target = null;
    retry.reason = '';
    retry.code = '';
    await load();
  } catch (caught) {
    error.value = caught;
  } finally {
    acting.value = false;
  }
}

onMounted(() => {
  void load();
  timer = setInterval(() => void load(), 60000);
});
onBeforeUnmount(() => {
  if (timer !== null) clearInterval(timer);
});
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-6xl flex-col gap-6">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('section.service.title') }}</h1>
          <p class="text-sm text-text-muted">{{ allowed ? $t('service.hint') : $t('service.forbidden') }}</p>
        </div>
        <button v-if="allowed" type="button" class="rounded-md border border-border-strong px-4 py-2 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="loading" data-action="refresh" @click="load">{{ $t('service.refresh') }}</button>
      </div>

      <ErrorNotice v-if="error" :error="error" />

      <template v-if="health">
        <p class="flex flex-wrap items-center gap-2 text-sm" data-state>
          <StatusBadge :tone="TONES[health.state]" solid :label="$t(`service.state.${health.state}`)" />
          <span class="text-text-muted">{{ $t('service.as_of', { date: dateTime(health.as_of) }) }}</span>
        </p>

        <section class="flex flex-col gap-2">
          <h2 class="text-lg font-semibold">{{ $t('service.components.title') }}</h2>
          <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <article v-for="(state, name) in health.components" :key="name" class="flex items-center justify-between gap-2 rounded-xl border border-border bg-surface p-4 text-sm" :data-component="name">
              <span class="font-medium">{{ $t(`service.components.${name}`, String(name)) }}</span>
              <StatusBadge :tone="TONES[state]" :label="$t(`service.state.${state}`)" />
            </article>
          </div>
        </section>

        <section class="flex flex-col gap-2">
          <h2 class="text-lg font-semibold">{{ $t('service.queues.title') }}</h2>
          <p class="text-sm text-text-muted">{{ $t('service.queues.hint', { warn: age(health.thresholds.warn_seconds), crit: age(health.thresholds.crit_seconds) }) }}</p>
          <div class="overflow-x-auto rounded-xl border border-border bg-surface">
            <table class="w-full min-w-[40rem] text-sm">
              <thead class="border-b border-border text-left text-text-muted">
                <tr>
                  <th class="px-4 py-3 font-medium">{{ $t('service.queues.name') }}</th>
                  <th class="px-4 py-3 font-medium">{{ $t('service.queues.waiting') }}</th>
                  <th class="px-4 py-3 font-medium">{{ $t('service.queues.oldest') }}</th>
                  <th class="px-4 py-3 font-medium">{{ $t('service.queues.problems') }}</th>
                  <th class="px-4 py-3 font-medium">{{ $t('service.queues.state') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-border">
                <tr v-for="name in QUEUES" :key="name" :data-queue="name">
                  <td class="px-4 py-3">
                    <span class="font-medium">{{ $t(`service.queues.names.${name}`) }}</span>
                    <span class="block text-[13px] text-text-muted">{{ $t(`service.queues.about.${name}`) }}</span>
                    <span v-if="name === 'billing_events'" class="block text-[13px] text-text-muted">{{ $t('service.queues.last_received', { date: dateTime(health.queues.billing_events.last_received_at ?? null) }) }}</span>
                    <template v-if="name === 'jobs'">
                      <span class="block text-[13px] text-text-muted" data-jobs-source>{{ $t('service.queues.jobs_source', { connection: health.queues.jobs.connection, queues: health.queues.jobs.queues.join(', ') }) }}</span>
                      <span class="block text-[13px] text-text-muted" data-jobs-detail>{{ $t('service.queues.jobs_detail', { delayed: health.queues.jobs.delayed, reserved: health.queues.jobs.reserved }) }}</span>
                      <span class="block text-[13px]" :class="health.queues.jobs.workers_seen === 0 && health.queues.jobs.waiting > 0 ? 'text-crit' : 'text-text-muted'" data-jobs-workers>{{ $t('service.queues.jobs_workers', { n: health.queues.jobs.workers_seen, date: dateTime(health.queues.jobs.last_processed_at) }) }}</span>
                      <span v-if="health.queues.jobs.error" class="block text-[13px] text-crit" data-jobs-error>{{ $t('service.queues.jobs_unavailable') }}</span>
                    </template>
                  </td>
                  <td class="px-4 py-3 tabular-nums">{{ health.queues[name].waiting }}</td>
                  <td class="px-4 py-3 tabular-nums">{{ age(health.queues[name].oldest_age_seconds) }}</td>
                  <td class="px-4 py-3 tabular-nums" :class="health.queues[name].problems > 0 ? 'text-warn' : ''">{{ health.queues[name].problems }}</td>
                  <td class="px-4 py-3"><StatusBadge :tone="TONES[health.queues[name].state]" :label="$t(`service.state.${health.queues[name].state}`)" /></td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section v-if="canManage" class="flex flex-col gap-2" data-section="dead-outbox">
          <h2 class="text-lg font-semibold">{{ $t('service.repair.title') }}</h2>
          <p class="text-sm text-text-muted">{{ $t('service.repair.hint') }}</p>
          <p v-if="deadOutbox.length === 0" class="text-sm text-text-muted">{{ $t('service.repair.empty') }}</p>
          <div v-else class="overflow-x-auto rounded-xl border border-border bg-surface">
            <table class="w-full min-w-[40rem] text-sm">
              <thead class="border-b border-border text-left text-text-muted"><tr>
                <th class="px-4 py-3 font-medium">{{ $t('service.repair.record') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('service.repair.error') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('service.repair.attempts') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('service.repair.created') }}</th>
                <th class="px-4 py-3 font-medium">{{ $t('service.repair.action') }}</th>
              </tr></thead>
              <tbody class="divide-y divide-border">
                <tr v-for="message in deadOutbox" :key="message.id" :data-dead-outbox="message.id">
                  <td class="px-4 py-3"><span class="block">{{ message.topic }}</span><span class="font-mono text-xs text-text-muted">{{ message.id }}</span></td>
                  <td class="px-4 py-3 font-mono">{{ message.error_code ?? '—' }}</td>
                  <td class="px-4 py-3 tabular-nums">{{ message.attempts }}</td>
                  <td class="px-4 py-3">{{ dateTime(message.created_at) }}</td>
                  <td class="px-4 py-3"><button type="button" class="rounded border border-border-strong px-3 py-1 font-medium text-primary hover:bg-primary-soft" :data-retry="message.id" @click="retry.target = message">{{ $t('service.repair.retry') }}</button></td>
                </tr>
              </tbody>
            </table>
            <p v-if="deadOutbox.length === 50" class="px-4 py-2 text-xs text-text-muted">{{ $t('service.repair.limit') }}</p>
          </div>
          <form v-if="retry.target" class="flex max-w-xl flex-col gap-3 rounded-xl border border-border-strong bg-surface p-4 text-sm" data-retry-form @submit.prevent="submitRetry">
            <p class="font-semibold">{{ $t('service.repair.confirm', { id: retry.target.id }) }}</p>
            <label class="flex flex-col gap-1"><span>{{ $t('service.repair.reason') }}</span><input v-model="retry.reason" required minlength="3" maxlength="500" class="rounded border border-border-strong px-3 py-2" data-field="retry-reason" /></label>
            <label class="flex flex-col gap-1"><span>{{ $t('service.repair.code') }}</span><input v-model="retry.code" required autocomplete="one-time-code" maxlength="32" class="rounded border border-border-strong px-3 py-2 font-mono" data-field="retry-code" /></label>
            <div class="flex gap-2">
              <button type="submit" class="rounded bg-primary px-4 py-2 font-semibold text-text-inverse disabled:opacity-50" :disabled="acting || retry.reason.trim().length < 3 || retry.code.trim().length < 6" data-action="retry-confirm">{{ $t('service.repair.retry') }}</button>
              <button type="button" class="rounded border border-border-strong px-4 py-2" @click="retry.target = null; retry.code = ''">{{ $t('common.cancel') }}</button>
            </div>
          </form>
        </section>

        <section class="grid gap-3 sm:grid-cols-2">
          <article class="flex flex-col gap-1 rounded-xl border border-border bg-surface p-4 text-sm" data-section="browser">
            <div class="flex items-center justify-between gap-2">
              <h2 class="text-lg font-semibold">{{ $t('service.browser.title') }}</h2>
              <StatusBadge :tone="TONES[health.browser_checks.state]" :label="$t(`service.state.${health.browser_checks.state}`)" />
            </div>
            <p>{{ $t('service.browser.counts', { waiting: health.browser_checks.waiting, running: health.browser_checks.running }) }}</p>
            <p>{{ $t('service.browser.oldest', { age: age(health.browser_checks.oldest_age_seconds) }) }}</p>
            <p :class="health.browser_checks.workers_seen === 0 ? 'text-crit' : ''">{{ $t('service.browser.workers', { n: health.browser_checks.workers_seen }) }}</p>
          </article>
          <article class="flex flex-col gap-1 rounded-xl border border-border bg-surface p-4 text-sm" data-section="sync">
            <div class="flex items-center justify-between gap-2">
              <h2 class="text-lg font-semibold">{{ $t('service.sync.title') }}</h2>
              <StatusBadge :tone="TONES[health.connector_sync.state]" :label="$t(`service.state.${health.connector_sync.state}`)" />
            </div>
            <p>{{ $t('service.sync.counts', { active: health.connector_sync.active, stale: health.connector_sync.stale, hours: health.thresholds.sync_stale_hours }) }}</p>
          </article>
        </section>

        <section class="flex flex-col gap-2 rounded-xl border border-border bg-surface p-4 text-sm" data-section="version">
          <h2 class="text-lg font-semibold">{{ $t('service.version.title') }}</h2>
          <p class="font-mono">{{ $t('service.version.release') }}: {{ health.version.app }} · {{ health.version.environment }} · PHP {{ health.version.php }} · Laravel {{ health.version.laravel }}</p>
        </section>
        <p class="text-xs text-text-muted">{{ $t('service.later') }}</p>
      </template>
    </div>
  </OwnerLayout>
</template>
