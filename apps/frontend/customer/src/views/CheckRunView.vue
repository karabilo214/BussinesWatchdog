<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { cancelRun, getRun } from '@/api/checks';
import { artifactLink } from '@/api/incidents';
import type { CheckRunDetail } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ReasonAction from '@/components/ReasonAction.vue';
import { ACTIVE_RUN_STATUSES, ATTEMPT_TONES, RUN_TONES, STEP_TONES } from '@/components/checks/tones';
import { useFormat } from '@/composables/useFormat';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';
import { StatusBadge } from '@bw/ui';

const POLL_MS = 10_000;

const props = defineProps<{ id: string }>();
const { canManageStores } = useRole();
const { dateTime } = useFormat();

const run = ref<CheckRunDetail | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const screenshots = ref<Record<string, string>>({});
const screenshotError = ref<unknown>(null);
let timer: ReturnType<typeof setInterval> | null = null;

const active = computed(() => run.value !== null && ACTIVE_RUN_STATUSES.includes(run.value.status));

function code(prefix: string, value: string | null): string | null {
  if (value === null) return null;

  return `${prefix}.${value}`;
}

async function load(): Promise<void> {
  try {
    run.value = await getRun(props.id);
    error.value = null;
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function showScreenshot(attemptId: string, artifactId: string): Promise<void> {
  screenshotError.value = null;

  try {
    screenshots.value = { ...screenshots.value, [attemptId]: (await artifactLink(artifactId)).url };
  } catch (caught) {
    screenshotError.value = caught;
  }
}

watch(active, (isActive) => {
  if (isActive && timer === null) {
    timer = setInterval(() => void load(), POLL_MS);
  } else if (!isActive && timer !== null) {
    clearInterval(timer);
    timer = null;
  }
});

watch(
  () => props.id,
  () => {
    loading.value = true;
    run.value = null;
    screenshots.value = {};
    void load();
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  if (timer !== null) clearInterval(timer);
});
</script>

<template>
  <AppLayout>
    <div class="flex flex-col gap-6">
      <RouterLink :to="{ name: 'checks', query: run ? { store: run.store_id } : {} }" class="text-sm text-primary hover:underline">← {{ $t('nav.checks') }}</RouterLink>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error && !run" :error="error">
        <button type="button" class="mt-2 self-start rounded-md bg-primary px-3 py-1.5 font-medium text-text-inverse hover:bg-primary-hover" @click="load">{{ $t('common.retry') }}</button>
      </ErrorNotice>

      <template v-else-if="run">
        <header class="flex flex-col gap-2">
          <div class="flex flex-wrap items-center gap-2">
            <StatusBadge :tone="RUN_TONES[run.status]" :label="$t(`checks.status.${run.status}`)" solid />
            <span class="text-sm text-text-muted">{{ $t(`checks.trigger.${run.trigger}`) }}</span>
          </div>
          <h1 class="text-2xl font-semibold">{{ $t('checks.run.title', { time: dateTime(run.scheduled_at) }) }}</h1>
          <p v-if="run.error_code" class="text-sm" data-run-error>{{ $te(`checks.error.${run.error_code}`) ? $t(`checks.error.${run.error_code}`) : run.error_code }}</p>
          <p class="text-[13px] text-text-muted">{{ $t(`checks.status_note.${run.status}`) }}</p>
        </header>

        <section class="grid gap-4 rounded-xl border border-border bg-surface p-5 sm:grid-cols-3" data-panel="run-times">
          <div class="flex flex-col gap-0.5">
            <span class="text-[13px] text-text-muted">{{ $t('checks.run.scheduled') }}</span>
            <span class="text-sm font-medium">{{ dateTime(run.scheduled_at) }}</span>
          </div>
          <div class="flex flex-col gap-0.5">
            <span class="text-[13px] text-text-muted">{{ $t('checks.run.started') }}</span>
            <span class="text-sm font-medium">{{ dateTime(run.started_at) }}</span>
          </div>
          <div class="flex flex-col gap-0.5">
            <span class="text-[13px] text-text-muted">{{ $t('checks.run.finished') }}</span>
            <span class="text-sm font-medium">{{ dateTime(run.finished_at) }}</span>
          </div>
        </section>

        <div v-if="active && canManageStores" class="rounded-xl border border-border bg-surface p-5">
          <ReasonAction
            id="cancel-run"
            :label="$t('checks.run.cancel')"
            :confirm-label="$t('checks.run.cancel_confirm')"
            :warning="$t('checks.run.cancel_warning')"
            :action="(reason: string) => cancelRun(run!.id, reason)"
            danger
            @done="load"
          />
        </div>

        <p v-if="run.attempts.length === 0" class="text-sm text-text-muted">{{ $t('checks.run.no_attempts') }}</p>
        <ErrorNotice v-if="screenshotError" :error="screenshotError" />

        <section
          v-for="attempt in run.attempts"
          :key="attempt.id"
          class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5"
          :aria-label="$t('checks.run.attempt', { n: attempt.attempt_number })"
          :data-attempt="attempt.attempt_number"
        >
          <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-lg font-semibold">{{ $t('checks.run.attempt', { n: attempt.attempt_number }) }}</h2>
            <StatusBadge :tone="ATTEMPT_TONES[attempt.status]" :label="$t(`checks.attempt_status.${attempt.status}`)" />
          </div>
          <p class="text-[13px] text-text-muted">
            {{ dateTime(attempt.started_at) }}<template v-if="attempt.location"> · {{ attempt.location }}</template><template v-if="attempt.browser_version"> · {{ attempt.browser_version }}</template>
          </p>
          <p v-if="code('checks.error', attempt.error_code)" class="text-sm">{{ $te(code('checks.error', attempt.error_code)!) ? $t(code('checks.error', attempt.error_code)!) : attempt.error_code }}</p>

          <ol v-if="attempt.steps.length > 0" class="flex flex-col divide-y divide-border rounded-lg border border-border">
            <li v-for="step in attempt.steps" :key="step.index" class="flex flex-wrap items-center justify-between gap-2 p-3 text-sm" :data-step="step.code">
              <span>{{ step.index + 1 }}. {{ $te(`incident.facts.step.${step.code}`) ? $t(`incident.facts.step.${step.code}`) : step.code }}</span>
              <span class="flex items-center gap-2">
                <span v-if="step.error_code" class="text-[13px] text-text-muted">{{ $te(`checks.error.${step.error_code}`) ? $t(`checks.error.${step.error_code}`) : step.error_code }}</span>
                <StatusBadge :tone="STEP_TONES[step.status]" :label="$t(`checks.step_status.${step.status}`)" />
              </span>
            </li>
          </ol>

          <div v-for="artifact in attempt.artifacts.filter((item) => item.kind === 'screenshot')" :key="artifact.id" class="flex flex-col gap-2">
            <div v-if="!screenshots[attempt.id]" class="flex flex-wrap items-center gap-3">
              <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft" @click="showScreenshot(attempt.id, artifact.id)">
                {{ $t('incident.evidence.show_screenshot') }}
              </button>
              <span class="text-[13px] text-text-muted">{{ $t('incident.evidence.screenshot_note') }}</span>
            </div>
            <img v-else :src="screenshots[attempt.id]" :alt="$t('incident.evidence.screenshot_alt')" class="max-w-full rounded-lg border border-border" data-screenshot />
          </div>

          <details v-if="attempt.diagnostics?.relevant_errors?.length" class="text-sm">
            <summary class="cursor-pointer text-text-muted">{{ $t('checks.run.diagnostics', { n: attempt.diagnostics.relevant_errors.length }) }}</summary>
            <ul class="mt-2 flex flex-col gap-1 font-mono text-[13px]">
              <li v-for="(entry, index) in attempt.diagnostics.relevant_errors" :key="index" class="break-all">{{ Object.entries(entry).map(([key, value]) => `${key}: ${String(value)}`).join(' · ') }}</li>
            </ul>
          </details>
        </section>

        <p class="text-[13px] text-text-muted">{{ $t('checks.scenario.no_purchase') }}</p>
      </template>
    </div>
  </AppLayout>
</template>
