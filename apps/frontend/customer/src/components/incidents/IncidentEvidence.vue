<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { artifactLink, getCheckRun } from '@/api/incidents';
import type { IncidentDetail, Signal } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import FindingsTable from '@/components/reconciliation/FindingsTable.vue';
import { useFormat } from '@/composables/useFormat';

const props = defineProps<{ incident: IncidentDetail }>();
const { t, te } = useI18n();
const { dateTime } = useFormat();

const screenshot = ref<string | null>(null);
const screenshotState = ref<'idle' | 'loading' | 'none'>('idle');
const screenshotError = ref<unknown>(null);

const signals = computed(() => props.incident.signals.filter((signal) => signal.signal_type !== 'reconciliation_finding'));
const latestRunId = computed(() => {
  const signal = [...props.incident.signals].reverse().find((item) => item.signal_type === 'browser_check');
  const id = signal?.evidence.check_run_id;

  return typeof id === 'string' ? id : null;
});

function text(value: unknown): string | null {
  return typeof value === 'string' && value !== '' ? value : null;
}

function count(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) ? value : null;
}

function translated(prefix: string, code: unknown): string | null {
  const value = text(code);

  if (value === null) return null;

  return te(`${prefix}.${value}`) ? t(`${prefix}.${value}`) : value;
}

/** Plain-language facts of one signal; only fields the backend documents as sanitized evidence. */
function facts(signal: Signal): string[] {
  const evidence = signal.evidence;
  const lines: string[] = [];

  if (signal.signal_type === 'payment_attempts') {
    lines.push(t('incident.facts.payment_streak', { method: text(evidence.payment_method) ?? '—', n: count(evidence.failure_streak) ?? '—', threshold: count(evidence.threshold) ?? '—' }));
    lines.push(t('incident.facts.last_success', { time: dateTime(text(evidence.last_success_at)) }));
  }

  if (signal.signal_type === 'connector_freshness') {
    lines.push(translated('incident.facts.connector_reason', evidence.reason) ?? t('incident.facts.connector_state', { state: text(evidence.state) ?? '—' }));
    lines.push(t('incident.facts.last_heartbeat', { time: dateTime(text(evidence.last_heartbeat_at)) }));

    if (count(evidence.backlog_count) !== null) {
      lines.push(t('incident.facts.backlog', { n: count(evidence.backlog_count)! }));
    }
  }

  if (signal.signal_type === 'browser_check') {
    lines.push(translated('incident.facts.check_error', evidence.error_code) ?? t('incident.facts.check_status', { status: text(evidence.run_status) ?? '—' }));

    if (text(evidence.failed_step) !== null) {
      lines.push(t('incident.facts.failed_step', { step: translated('incident.facts.step', evidence.failed_step)! }));
    }

    if (count(evidence.attempts) !== null) {
      lines.push(t('incident.facts.attempts', { n: count(evidence.attempts)! }));
    }
  }

  return lines;
}

async function showScreenshot(): Promise<void> {
  if (latestRunId.value === null) return;

  screenshotState.value = 'loading';
  screenshotError.value = null;

  try {
    const run = await getCheckRun(latestRunId.value);
    const artifact = run.attempts.flatMap((attempt) => attempt.artifacts).reverse().find((item) => item.kind === 'screenshot');

    if (artifact === undefined) {
      screenshotState.value = 'none';

      return;
    }

    screenshot.value = (await artifactLink(artifact.id)).url;
    screenshotState.value = 'idle';
  } catch (error) {
    screenshotError.value = error;
    screenshotState.value = 'idle';
  }
}
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="evidence-title" data-panel="incident-evidence">
    <h2 id="evidence-title" class="text-lg font-semibold">{{ $t('incident.evidence.title') }}</h2>

    <FindingsTable v-if="incident.findings.length > 0" :findings="incident.findings" :show-status="false" />

    <ul v-if="signals.length > 0" class="flex flex-col gap-3">
      <li v-for="signal in signals" :key="signal.id" class="flex flex-col gap-1 rounded-lg border border-border p-3 text-sm" :data-signal-type="signal.signal_type">
        <div class="flex flex-wrap items-center justify-between gap-2 text-[13px] text-text-muted">
          <span>{{ $t(`incident.signal_type.${signal.signal_type}`) }}</span>
          <span>{{ dateTime(signal.detected_at) }} · {{ $t(`incident.confidence.${signal.confidence}`) }}</span>
        </div>
        <p v-for="(line, index) in facts(signal)" :key="index">{{ line }}</p>
      </li>
    </ul>

    <p v-if="incident.findings.length === 0 && signals.length === 0" class="text-sm text-text-muted">{{ $t('incident.evidence.none') }}</p>

    <div v-if="latestRunId" class="flex flex-col gap-3 border-t border-border pt-4">
      <div class="flex flex-wrap items-center gap-3">
        <button
          type="button"
          class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
          :disabled="screenshotState === 'loading'"
          @click="showScreenshot"
        >
          {{ $t('incident.evidence.show_screenshot') }}
        </button>
        <span class="text-[13px] text-text-muted">{{ $t('incident.evidence.screenshot_note') }}</span>
        <RouterLink :to="{ name: 'check-run', params: { id: latestRunId } }" class="text-sm text-primary hover:underline">{{ $t('incident.evidence.open_run') }}</RouterLink>
      </div>
      <p v-if="screenshotState === 'none'" class="text-sm text-text-muted">{{ $t('incident.evidence.no_screenshot') }}</p>
      <ErrorNotice v-if="screenshotError" :error="screenshotError" />
      <img v-if="screenshot" :src="screenshot" :alt="$t('incident.evidence.screenshot_alt')" class="max-w-full rounded-lg border border-border" data-screenshot />
    </div>
  </section>
</template>
