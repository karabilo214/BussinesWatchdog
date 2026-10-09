<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { checkVerification, getVerification, startVerification } from '@/api/stores';
import type { Store, StoreVerification, VerificationMethod } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge, type Tone } from '@bw/ui';
import CopyField from './CopyField.vue';
import ErrorNotice from './ErrorNotice.vue';

const POLL_MS = 15_000;

/** reason_code values the backend can report (StoreVerificationService, SafeHttpFetcher). */
const KNOWN_REASONS = [
  'dns_record_missing',
  'challenge_mismatch',
  'store_url_changed',
  'connection_failed',
  'dns_failure',
  'http_status_unexpected',
  'redirect_not_allowed',
  'response_too_large',
  'unsafe_destination',
];

const STATE_TONES: Record<StoreVerification['state'], Tone> = { verified: 'ok', pending: 'unknown', failed: 'crit', expired: 'unknown' };

const props = defineProps<{ store: Store; canManage: boolean }>();
const emit = defineEmits<{ verified: [] }>();
const { dateTime } = useFormat();

const verification = ref<StoreVerification | null>(null);
const loading = ref(true);
const loadError = ref<unknown>(null);
const actionError = ref<unknown>(null);
const busy = ref(false);
const method = ref<VerificationMethod>(props.store.coverage.connector.state === 'not_connected' ? 'dns' : 'plugin_challenge');
let timer: ReturnType<typeof setInterval> | null = null;

const connectorPresent = computed(() => props.store.coverage.connector.state !== 'not_connected');
const verified = computed(() => props.store.verified_at !== null);
const pending = computed(() => verification.value?.state === 'pending');
const reasonKey = computed(() => {
  const code = verification.value?.reason_code;

  if (code === null || code === undefined) {
    return null;
  }

  return `store.verification.reason.${KNOWN_REASONS.includes(code) ? code : 'other'}`;
});

function settle(next: StoreVerification | null): void {
  const wasVerified = verification.value?.state === 'verified';
  verification.value = next;

  if (next?.state === 'verified' && !wasVerified && !verified.value) {
    emit('verified');
  }
}

async function load(): Promise<void> {
  try {
    settle(await getVerification(props.store.id));
    loadError.value = null;
  } catch (error) {
    loadError.value = error;
  } finally {
    loading.value = false;
  }
}

async function start(): Promise<void> {
  busy.value = true;
  actionError.value = null;

  try {
    settle(await startVerification(props.store.id, method.value));
  } catch (error) {
    actionError.value = error;
  } finally {
    busy.value = false;
  }
}

async function checkNow(): Promise<void> {
  busy.value = true;
  actionError.value = null;

  try {
    const checked = await checkVerification(props.store.id);
    settle({ ...checked, instructions: checked.instructions ?? verification.value?.instructions });
  } catch (error) {
    actionError.value = error;
  } finally {
    busy.value = false;
  }
}

watch(
  pending,
  (isPending) => {
    if (isPending && timer === null) {
      timer = setInterval(load, POLL_MS);
    } else if (!isPending && timer !== null) {
      clearInterval(timer);
      timer = null;
    }
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  if (timer !== null) {
    clearInterval(timer);
  }
});

void load();
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="verification-title" data-panel="verification">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex flex-col gap-1">
        <h2 id="verification-title" class="text-lg font-semibold">{{ $t('store.verification.title') }}</h2>
        <p class="max-w-3xl text-sm text-text-muted">{{ $t('store.verification.subtitle') }}</p>
      </div>
      <StatusBadge v-if="verified" tone="ok" :label="$t('store.verification.verified_at', { time: dateTime(store.verified_at) })" />
      <StatusBadge v-else tone="unknown" :label="$t('store.verification.not_verified')" />
    </div>

    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <ErrorNotice v-else-if="loadError" :error="loadError" />

    <template v-else-if="!verified">
      <div v-if="verification && verification.state !== 'verified'" class="flex flex-col gap-4 rounded-lg border border-border p-4" data-verification-state>
        <div class="flex flex-wrap items-center gap-3">
          <StatusBadge :tone="STATE_TONES[verification.state]" :label="$t(`store.verification.state.${verification.state}`)" />
          <span class="text-[13px] text-text-muted">{{ $t(`store.verification.method.${verification.method}`) }}</span>
        </div>

        <p v-if="reasonKey" class="text-sm text-text-muted" data-reason>{{ $t(reasonKey) }}</p>

        <template v-if="pending && verification.instructions">
          <template v-if="verification.instructions.type === 'dns_txt'">
            <p class="text-sm">{{ $t('store.verification.dns_help') }}</p>
            <CopyField id="dns-name" :label="$t('store.verification.dns_name')" :value="verification.instructions.record_name" />
            <CopyField id="dns-value" :label="$t('store.verification.dns_value')" :value="verification.instructions.txt_value" />
          </template>
          <p v-else class="text-sm">{{ $t('store.verification.plugin_help') }}</p>
        </template>

        <p v-if="pending" class="text-[13px] text-text-muted" role="status">
          {{ $t('store.verification.auto_check', { time: dateTime(verification.expires_at) }) }}
          <template v-if="verification.last_checked_at"> {{ $t('store.verification.last_checked', { time: dateTime(verification.last_checked_at) }) }}</template>
        </p>

        <div v-if="canManage && pending">
          <button
            type="button"
            class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
            :disabled="busy"
            @click="checkNow"
          >
            {{ $t('store.verification.check_now') }}
          </button>
        </div>
      </div>

      <ErrorNotice v-if="actionError" :error="actionError" />

      <form v-if="canManage" class="flex flex-col gap-3" @submit.prevent="start">
        <fieldset class="flex flex-col gap-2">
          <legend class="mb-1 text-sm font-medium">{{ pending ? $t('store.verification.restart') : $t('store.verification.choose') }}</legend>
          <label class="flex items-start gap-2 text-sm" :class="{ 'text-text-muted': !connectorPresent }">
            <input v-model="method" type="radio" value="plugin_challenge" class="mt-0.5 size-4 accent-primary" :disabled="!connectorPresent" />
            <span>
              <span class="font-medium">{{ $t('store.verification.method.plugin_challenge') }}</span>
              <span class="block text-[13px] text-text-muted">
                {{ connectorPresent ? $t('store.verification.plugin_hint') : $t('store.verification.plugin_needs_connector') }}
              </span>
            </span>
          </label>
          <label class="flex items-start gap-2 text-sm">
            <input v-model="method" type="radio" value="dns" class="mt-0.5 size-4 accent-primary" />
            <span>
              <span class="font-medium">{{ $t('store.verification.method.dns') }}</span>
              <span class="block text-[13px] text-text-muted">{{ $t('store.verification.dns_hint') }}</span>
            </span>
          </label>
        </fieldset>
        <div>
          <button
            type="submit"
            class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
            :disabled="busy || (method === 'plugin_challenge' && !connectorPresent)"
          >
            {{ $t('store.verification.start') }}
          </button>
        </div>
      </form>
    </template>
  </section>
</template>
