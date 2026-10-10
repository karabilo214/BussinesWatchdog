<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { listRuns, startRun } from '@/api/checks';
import type { CheckRunSummary, CheckScenario } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import { ACTIVE_RUN_STATUSES, RUN_TONES } from './tones';

const POLL_MS = 10_000;

const props = defineProps<{ storeId: string; scenario: CheckScenario | null; canRun: boolean; ready: boolean }>();
const emit = defineEmits<{ finished: [] }>();
const { dateTime } = useFormat();

const runs = ref<CheckRunSummary[]>([]);
const nextCursor = ref<string | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const starting = ref(false);
const startError = ref<unknown>(null);
let timer: ReturnType<typeof setInterval> | null = null;

const active = computed(() => runs.value.some((run) => ACTIVE_RUN_STATUSES.includes(run.status)));

async function load(more = false): Promise<void> {
  try {
    const wasActive = active.value;
    const page = await listRuns(props.storeId, more ? (nextCursor.value ?? undefined) : undefined);
    runs.value = more ? [...runs.value, ...page.data] : page.data;
    nextCursor.value = more || nextCursor.value === null ? page.next_cursor : nextCursor.value;
    error.value = null;

    if (wasActive && !active.value) {
      emit('finished');
    }
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function start(): Promise<void> {
  if (props.scenario === null) return;

  starting.value = true;
  startError.value = null;

  try {
    await startRun(props.storeId, props.scenario.id);
    await load();
  } catch (caught) {
    startError.value = caught;
  } finally {
    starting.value = false;
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
  () => props.storeId,
  () => {
    loading.value = true;
    nextCursor.value = null;
    void load();
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  if (timer !== null) clearInterval(timer);
});
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="runs-title" data-panel="runs">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 id="runs-title" class="text-lg font-semibold">{{ $t('checks.runs.title') }}</h2>
      <button
        v-if="canRun && scenario"
        type="button"
        class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
        :disabled="starting || active || !ready"
        @click="start"
      >
        {{ $t('checks.runs.run_now') }}
      </button>
    </div>
    <p v-if="canRun && scenario && !ready" class="text-[13px] text-text-muted">{{ $t('checks.runs.not_ready') }}</p>
    <p v-if="active" class="text-sm text-text-muted" role="status">{{ $t('checks.runs.in_progress') }}</p>
    <ErrorNotice v-if="startError" :error="startError" />

    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <ErrorNotice v-else-if="error" :error="error" />
    <p v-else-if="runs.length === 0" class="text-sm text-text-muted">{{ $t('checks.runs.empty') }}</p>

    <ul v-else class="flex flex-col divide-y divide-border rounded-lg border border-border">
      <li v-for="run in runs" :key="run.id" class="flex flex-wrap items-center justify-between gap-3 p-3 text-sm" :data-run-id="run.id">
        <div class="flex flex-col gap-0.5">
          <RouterLink :to="{ name: 'check-run', params: { id: run.id } }" class="font-medium text-primary hover:underline">
            {{ dateTime(run.finished_at ?? run.started_at ?? run.scheduled_at) }}
          </RouterLink>
          <span class="text-[13px] text-text-muted">
            {{ $t(`checks.trigger.${run.trigger}`) }}<template v-if="run.error_code"> · {{ $te(`checks.error.${run.error_code}`) ? $t(`checks.error.${run.error_code}`) : run.error_code }}</template>
          </span>
        </div>
        <StatusBadge :tone="RUN_TONES[run.status]" :label="$t(`checks.status.${run.status}`)" />
      </li>
    </ul>

    <div v-if="nextCursor">
      <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium text-primary hover:bg-primary-soft" @click="load(true)">
        {{ $t('incidents.load_more') }}
      </button>
    </div>
  </section>
</template>
