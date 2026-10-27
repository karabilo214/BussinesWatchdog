<script setup lang="ts">
import { computed, ref } from 'vue';
import { checkProviderWebhook } from '@/api/providers';
import type { Integration } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge, type Tone } from '@bw/ui';
import ErrorNotice from './ErrorNotice.vue';

type Stage = 'none' | 'waiting' | 'rejected' | 'working';

const STAGE_TONES: Record<Stage, Tone> = { none: 'unknown', waiting: 'unknown', rejected: 'crit', working: 'ok' };
const NAMES = { stripe: 'Stripe', paypal: 'PayPal' } as const;

const props = defineProps<{ integration: Integration; provider: 'stripe' | 'paypal'; configured: boolean; canManage: boolean }>();
const emit = defineEmits<{ reload: [] }>();
const { dateTime } = useFormat();

const busy = ref(false);
const error = ref<unknown>(null);

const health = computed(() => props.integration.health ?? {});
const delivered = computed(() => health.value.webhook?.last_received_at ?? null);
const rejected = computed(() => {
  const value = health.value.webhook_rejected;

  return value && (delivered.value === null || value.at > delivered.value) ? value : null;
});
const check = computed(() => health.value.webhook_check ?? null);
const stage = computed<Stage>(() => {
  if (!props.configured) return 'none';
  if (rejected.value) return 'rejected';

  return delivered.value ? 'working' : 'waiting';
});
const name = computed(() => NAMES[props.provider]);

async function runCheck(): Promise<void> {
  busy.value = true;
  error.value = null;

  try {
    await checkProviderWebhook(props.integration.id);
    emit('reload');
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-1" data-provider-webhook>
    <StatusBadge class="self-start" :tone="STAGE_TONES[stage]" :label="$t(`provider.webhook_stage.${stage}`)" :data-webhook-stage="stage" />
    <span class="text-[13px]" :class="stage === 'rejected' ? 'text-crit' : 'text-text-muted'" data-webhook-state>
      <template v-if="stage === 'none'">{{ $t(provider === 'stripe' ? 'provider.webhook_off' : 'paypal.webhook_off') }}</template>
      <template v-else-if="stage === 'working'">{{ $t('provider.webhook_working', { type: health.webhook?.last_type ?? '', time: dateTime(delivered) }) }}</template>
      <template v-else-if="stage === 'rejected'">{{ $t(`provider.webhook_rejected_${provider}`, { type: rejected!.topic ?? '', time: dateTime(rejected!.at) }) }}</template>
      <template v-else>{{ $t(`provider.webhook_waiting_${provider}`) }}</template>
    </span>

    <div v-if="check && configured && stage !== 'working'" class="flex flex-col gap-0.5 rounded-md bg-surface-muted p-2 text-[13px]" data-webhook-check>
      <span class="text-text-muted">{{ $t('provider.webhook_checked', { time: dateTime(check.checked_at) }) }}</span>
      <span v-if="check.state === 'ok'" data-check-ok>✓ {{ $t('provider.webhook_check_ok', { provider: name }) }}</span>
      <span v-else-if="check.state === 'permission_missing'" class="text-warn">{{ $t('provider.webhook_check_permission') }}</span>
      <span v-else-if="check.state === 'not_readable'" class="text-warn">
        {{ $t('provider.webhook_check_not_readable') }}<template v-if="check.error_code && $te(`errors.${check.error_code}`)"> {{ $t(`errors.${check.error_code}`) }}</template>
      </span>
      <span v-for="issue in check.issues ?? []" v-else :key="issue" class="text-warn" :data-issue="issue">
        ✗ {{ $t(`provider.webhook_issue.${issue}`, { provider: name, events: (check.missing_events ?? []).join(', ') }) }}
      </span>
    </div>

    <button
      v-if="canManage && configured && integration.status !== 'pending'"
      type="button"
      class="self-start rounded-md border border-border-strong bg-surface px-3 py-1 text-[13px] font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
      :disabled="busy"
      data-action="check-webhook"
      @click="runCheck"
    >
      {{ $t('provider.webhook_check') }}
    </button>
    <ErrorNotice v-if="error" :error="error" />
  </div>
</template>
