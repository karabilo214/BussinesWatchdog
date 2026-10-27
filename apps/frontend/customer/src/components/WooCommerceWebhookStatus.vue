<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { checkWooCommerceWebhooks, setUpWooCommerceWebhook, type WooCommerceWebhookSetup } from '@/api/providers';
import type { Integration } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge, type Tone } from '@bw/ui';
import CopyField from './CopyField.vue';
import ErrorNotice from './ErrorNotice.vue';

type Stage = 'none' | 'waiting' | 'reached' | 'rejected' | 'working';

const POLL_MS = 10_000;
const POLL_LIMIT_MS = 15 * 60_000;
const STAGE_TONES: Record<Stage, Tone> = { none: 'unknown', waiting: 'unknown', reached: 'warn', rejected: 'crit', working: 'ok' };

const props = defineProps<{ integration: Integration; canManage: boolean }>();
const emit = defineEmits<{ reload: [] }>();
const { dateTime } = useFormat();

const setup = ref<WooCommerceWebhookSetup | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);

const health = computed(() => props.integration.health ?? {});
const delivered = computed(() => health.value.webhook?.last_received_at ?? null);
const rejected = computed(() => {
  const value = health.value.webhook_rejected;

  return value && (delivered.value === null || value.at > delivered.value) ? value : null;
});
const check = computed(() => health.value.webhook_check ?? null);
const ping = computed(() => health.value.webhook_ping ?? null);
const stage = computed<Stage>(() => {
  if (!health.value.webhook_set_up_at) return 'none';
  if (rejected.value) return 'rejected';
  if (delivered.value) return 'working';
  if (ping.value?.verified || check.value?.state === 'ok') return 'reached';

  return 'waiting';
});
const topicProblems = computed(() => Object.entries(check.value?.topics ?? {}).filter(([, state]) => state !== 'active'));

async function act(action: () => Promise<void>): Promise<void> {
  busy.value = true;
  error.value = null;

  try {
    await action();
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

function setUp(): Promise<void> {
  return act(async () => {
    setup.value = await setUpWooCommerceWebhook(props.integration.id);
    emit('reload');
  });
}

function runCheck(): Promise<void> {
  return act(async () => {
    await checkWooCommerceWebhooks(props.integration.id);
    emit('reload');
  });
}

let timer: ReturnType<typeof setInterval> | null = null;
let pollingSince = 0;

function stopPolling(): void {
  if (timer !== null) clearInterval(timer);
  timer = null;
}

watch(
  stage,
  (current) => {
    if (current === 'waiting' || current === 'reached') {
      if (timer === null) {
        pollingSince = Date.now();
        timer = setInterval(() => (Date.now() - pollingSince > POLL_LIMIT_MS ? stopPolling() : emit('reload')), POLL_MS);
      }
    } else {
      stopPolling();
    }
  },
  { immediate: true },
);

onBeforeUnmount(stopPolling);
</script>

<template>
  <div class="flex flex-col gap-2 rounded-md border border-border p-3 text-sm" data-api-webhook>
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="font-medium">{{ $t('store.api.webhook_title') }}</p>
      <StatusBadge :tone="STAGE_TONES[stage]" :label="$t(`store.api.webhook_stage.${stage}`)" :data-webhook-stage="stage" />
    </div>
    <p class="text-[13px] text-text-muted">{{ $t('store.api.webhook_intro') }}</p>

    <p class="text-[13px]" data-webhook-state>
      <template v-if="stage === 'none'">{{ $t('store.api.webhook_none') }}</template>
      <template v-else-if="stage === 'working'">{{ $t('store.api.webhook_working', { topic: health.webhook?.last_type ?? '', time: dateTime(delivered) }) }}</template>
      <template v-else-if="stage === 'rejected'">
        <span class="text-crit">{{ $t('store.api.webhook_rejected', { topic: rejected!.topic, time: dateTime(rejected!.at) }) }}</span>
      </template>
      <template v-else-if="stage === 'reached'">
        {{ ping?.verified ? $t('store.api.webhook_reached_ping', { id: ping.webhook_id, time: dateTime(ping.received_at) }) : $t('store.api.webhook_reached') }}
        {{ $t('store.api.webhook_sign_test') }}
      </template>
      <template v-else>
        {{ ping ? $t('store.api.webhook_ping_unverified', { id: ping.webhook_id, time: dateTime(ping.received_at) }) : $t('store.api.webhook_waiting') }}
      </template>
    </p>

    <div v-if="check && stage !== 'none' && stage !== 'working'" class="flex flex-col gap-1 rounded-md bg-surface-muted p-2 text-[13px]" data-webhook-check>
      <p class="text-text-muted">{{ $t('store.api.webhook_checked', { time: dateTime(check.checked_at) }) }}</p>
      <p v-if="check.state === 'not_readable'" class="text-warn">
        {{ $t('store.api.webhook_not_readable') }}
        <template v-if="check.error_code && $te(`errors.${check.error_code}`)"> {{ $t(`errors.${check.error_code}`) }}</template>
      </p>
      <ul v-else class="flex flex-col gap-0.5">
        <li v-for="(state, topic) in check.topics" :key="topic" :data-topic="topic" :class="state === 'active' ? '' : 'text-warn'">
          {{ state === 'active' ? '✓' : '✗' }} {{ topic }} — {{ $t(`store.api.webhook_topic.${state}`) }}
        </li>
        <li v-for="issue in check.issues ?? []" :key="issue" class="text-warn" :data-issue="issue">{{ $t(`store.api.webhook_issue.${issue}`) }}</li>
      </ul>
      <p v-if="check.state === 'ok' && topicProblems.length === 0 && stage !== 'reached'" class="text-ok">{{ $t('store.api.webhook_check_ok') }}</p>
    </div>

    <template v-if="setup">
      <ol class="list-decimal space-y-1 pl-5 text-[13px]">
        <li>{{ $t('store.api.webhook_step_open') }}</li>
        <li>{{ $t('store.api.webhook_step_fields', { topics: setup.topics.join(', ') }) }}</li>
        <li>{{ $t('store.api.webhook_step_save') }}</li>
        <li>{{ $t('store.api.webhook_step_check') }}</li>
      </ol>
      <CopyField id="woo-webhook-url" :label="$t('store.api.webhook_url')" :value="setup.delivery_url" />
      <CopyField id="woo-webhook-secret" :label="$t('store.api.webhook_secret')" :value="setup.secret" />
      <p class="text-[13px] text-warn">{{ $t('store.api.webhook_secret_once') }}</p>
    </template>

    <p class="text-[13px] text-text-muted">{{ $t('store.api.webhook_effect') }}</p>

    <div v-if="canManage && integration.status !== 'pending'" class="flex flex-wrap gap-2">
      <button
        v-if="health.webhook_set_up_at"
        type="button"
        class="rounded-md bg-primary px-3 py-1.5 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
        :disabled="busy"
        data-action="check-webhook"
        @click="runCheck"
      >
        {{ $t('store.api.webhook_check') }}
      </button>
      <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy" data-action="set-up-webhook" @click="setUp">
        {{ health.webhook_set_up_at ? $t('store.api.webhook_renew') : $t('store.api.webhook_set_up') }}
      </button>
    </div>
    <ErrorNotice v-if="error" :error="error" />
  </div>
</template>
