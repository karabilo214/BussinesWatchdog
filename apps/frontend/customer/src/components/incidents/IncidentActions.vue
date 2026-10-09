<script setup lang="ts">
import { computed, ref } from 'vue';
import { ApiError } from '@bw/api-client';
import { acknowledgeIncident, getCheckRun, recheckMoney, resolveIncident, revokeSuppression, snoozeIncident, startManualCheck } from '@/api/incidents';
import type { IncidentDetail } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';

const SNOOZE_HOURS = [1, 4, 24, 72, 168];

const props = defineProps<{ incident: IncidentDetail; canHandle: boolean; canManage: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const { dateTime } = useFormat();

const mode = ref<'idle' | 'resolve' | 'snooze'>('idle');
const reason = ref('');
const snoozeHours = ref(24);
const busy = ref(false);
const error = ref<unknown>(null);
const notice = ref<string | null>(null);

const active = computed(() => props.incident.state !== 'resolved');
const reasonValid = computed(() => reason.value.trim().length >= 5 && reason.value.trim().length <= 1000);
const checkRunId = computed(() => {
  const signal = [...props.incident.signals].reverse().find((item) => item.signal_type === 'browser_check');
  const id = signal?.evidence.check_run_id;

  return typeof id === 'string' ? id : null;
});
const recheck = computed<'money' | 'checkout' | null>(() => {
  if (props.incident.family === 'money' && props.canHandle) return 'money';
  if (props.incident.family === 'checkout' && checkRunId.value !== null && props.canManage) return 'checkout';

  return null;
});

function open(next: 'resolve' | 'snooze'): void {
  mode.value = mode.value === next ? 'idle' : next;
  reason.value = '';
  error.value = null;
  notice.value = null;
}

async function run(action: () => Promise<unknown>, success: string | null = null): Promise<void> {
  busy.value = true;
  error.value = null;
  notice.value = null;

  try {
    await action();
    mode.value = 'idle';
    reason.value = '';
    notice.value = success;
    emit('changed');
  } catch (caught) {
    error.value = caught;

    if (caught instanceof ApiError && caught.status === 409 && caught.code !== 'check_run_already_active') {
      emit('changed');
    }
  } finally {
    busy.value = false;
  }
}

function acknowledge(): Promise<void> {
  return run(() => acknowledgeIncident(props.incident));
}

function resolve(): Promise<void> {
  return run(() => resolveIncident(props.incident, reason.value.trim()));
}

function snooze(): Promise<void> {
  return run(() => snoozeIncident(props.incident.id, new Date(Date.now() + snoozeHours.value * 3_600_000), reason.value.trim()));
}

function unsnooze(): Promise<void> {
  const suppression = props.incident.active_suppression;

  return suppression === null ? Promise.resolve() : run(() => revokeSuppression(suppression.id));
}

function startRecheck(): Promise<void> {
  if (recheck.value === 'money') {
    const orderIds = [...new Set(props.incident.findings.map((finding) => finding.order_id).filter((id): id is string => id !== null))];

    return run(() => recheckMoney(props.incident.store_id, orderIds), 'incident.actions.recheck_money_done');
  }

  return run(async () => {
    const previous = await getCheckRun(checkRunId.value!);
    await startManualCheck(props.incident.store_id, previous.scenario_id);
  }, 'incident.actions.recheck_check_done');
}
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="actions-title" data-panel="incident-actions">
    <h2 id="actions-title" class="text-lg font-semibold">{{ $t('incident.actions.title') }}</h2>

    <div v-if="incident.active_suppression" class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-note-border bg-note-soft px-3 py-2 text-sm" data-suppression>
      <span>{{ $t('incident.actions.snoozed_until', { time: dateTime(incident.active_suppression.ends_at) }) }}</span>
      <button v-if="canManage" type="button" class="font-medium text-primary hover:underline disabled:opacity-60" :disabled="busy" @click="unsnooze">
        {{ $t('incident.actions.unsnooze') }}
      </button>
    </div>

    <p v-if="!canHandle" class="text-sm text-text-muted">{{ $t('incident.actions.read_only') }}</p>

    <div v-else class="flex flex-wrap gap-2">
      <button
        v-if="incident.state === 'open'"
        type="button"
        class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
        :disabled="busy"
        @click="acknowledge"
      >
        {{ $t('incident.actions.acknowledge') }}
      </button>
      <button
        v-if="active"
        type="button"
        class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium hover:bg-surface-muted"
        :aria-expanded="mode === 'resolve'"
        @click="open('resolve')"
      >
        {{ $t('incident.actions.resolve') }}
      </button>
      <button
        v-if="active && canManage && !incident.active_suppression"
        type="button"
        class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium hover:bg-surface-muted"
        :aria-expanded="mode === 'snooze'"
        @click="open('snooze')"
      >
        {{ $t('incident.actions.snooze') }}
      </button>
      <button
        v-if="recheck"
        type="button"
        class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
        :disabled="busy"
        @click="startRecheck"
      >
        {{ $t(`incident.actions.recheck_${recheck}`) }}
      </button>
    </div>

    <p v-if="incident.state === 'resolved'" class="text-sm text-text-muted">{{ $t('incident.actions.resolved_note') }}</p>

        <p v-if="incident.state === 'acknowledged'" class="text-[13px] text-text-muted">{{ $t('incident.actions.acknowledged_note') }}</p>

    <form v-if="mode === 'resolve'" class="flex flex-col gap-3 rounded-lg border border-border p-4" @submit.prevent="resolve">
      <label for="resolve-reason" class="text-sm font-medium">{{ $t('incident.actions.resolve_reason') }}</label>
      <textarea id="resolve-reason" v-model="reason" rows="3" maxlength="1000" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm"></textarea>
      <p class="text-[13px] text-text-muted">{{ $t('incident.actions.resolve_note') }}</p>
      <div>
        <button type="submit" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !reasonValid">
          {{ $t('incident.actions.resolve_confirm') }}
        </button>
      </div>
    </form>

    <form v-if="mode === 'snooze'" class="flex flex-col gap-3 rounded-lg border border-border p-4" @submit.prevent="snooze">
      <label for="snooze-duration" class="text-sm font-medium">{{ $t('incident.actions.snooze_for') }}</label>
      <select id="snooze-duration" v-model.number="snoozeHours" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm sm:max-w-xs">
        <option v-for="hours in SNOOZE_HOURS" :key="hours" :value="hours">{{ $t(`incident.actions.snooze_hours.h${hours}`) }}</option>
      </select>
      <label for="snooze-reason" class="text-sm font-medium">{{ $t('incident.actions.snooze_reason') }}</label>
      <textarea id="snooze-reason" v-model="reason" rows="2" maxlength="1000" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm"></textarea>
      <p class="text-[13px] text-text-muted">{{ $t('incident.actions.snooze_note') }}</p>
      <div>
        <button type="submit" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !reasonValid">
          {{ $t('incident.actions.snooze_confirm') }}
        </button>
      </div>
    </form>

    <p v-if="notice" class="text-sm text-ok" role="status">{{ $t(notice) }}</p>
    <ErrorNotice v-if="error" :error="error" />
  </section>
</template>
