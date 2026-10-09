<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { createPairingCode, listStoreIntegrations, revokeIntegration } from '@/api/stores';
import type { Integration, PairingCode } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import CopyField from './CopyField.vue';
import ErrorNotice from './ErrorNotice.vue';
import { INTEGRATION_TONES, providerName } from './integrationTones';

const POLL_MS = 15_000;

const props = defineProps<{ storeId: string; canManage: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const { dateTime } = useFormat();

const integrations = ref<Integration[]>([]);
const loading = ref(true);
const loadError = ref<unknown>(null);
const pairing = ref<PairingCode | null>(null);
const pairingBusy = ref(false);
const actionError = ref<unknown>(null);
const confirmingRevoke = ref<string | null>(null);
const revoking = ref(false);
let timer: ReturnType<typeof setInterval> | null = null;

const connectors = computed(() => integrations.value.filter((integration) => integration.source_authority === 'store_reported'));
const liveConnectors = computed(() => connectors.value.filter((integration) => integration.status !== 'revoked' && integration.status !== 'disabled'));

async function load(): Promise<void> {
  try {
    const before = liveConnectors.value.length;
    integrations.value = await listStoreIntegrations(props.storeId);
    loadError.value = null;

    if (pairing.value !== null && liveConnectors.value.length > before) {
      pairing.value = null;
      emit('changed');
    }
  } catch (error) {
    loadError.value = error;
  } finally {
    loading.value = false;
  }
}

async function issueCode(): Promise<void> {
  pairingBusy.value = true;
  actionError.value = null;

  try {
    pairing.value = await createPairingCode(props.storeId);
  } catch (error) {
    actionError.value = error;
  } finally {
    pairingBusy.value = false;
  }
}

async function revoke(integration: Integration): Promise<void> {
  revoking.value = true;
  actionError.value = null;

  try {
    await revokeIntegration(integration.id);
    confirmingRevoke.value = null;
    await load();
    emit('changed');
  } catch (error) {
    actionError.value = error;
  } finally {
    revoking.value = false;
  }
}

watch(pairing, (code) => {
  if (code !== null && timer === null) {
    timer = setInterval(load, POLL_MS);
  } else if (code === null && timer !== null) {
    clearInterval(timer);
    timer = null;
  }
});

onBeforeUnmount(() => {
  if (timer !== null) {
    clearInterval(timer);
  }
});

void load();
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="connector-title" data-panel="connector">
    <div class="flex flex-col gap-1">
      <h2 id="connector-title" class="text-lg font-semibold">{{ $t('store.connector.title') }}</h2>
      <p class="max-w-3xl text-sm text-text-muted">{{ $t('store.connector.subtitle') }}</p>
    </div>

    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <ErrorNotice v-else-if="loadError" :error="loadError" />

    <template v-else>
      <p v-if="connectors.length === 0" class="text-sm text-text-muted">{{ $t('store.connector.none') }}</p>

      <ul v-else class="flex flex-col divide-y divide-border rounded-lg border border-border">
        <li v-for="integration in connectors" :key="integration.id" class="flex flex-col gap-3 p-4" :data-integration-id="integration.id">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-col gap-0.5">
              <span class="font-medium">{{ providerName(integration.provider) }}</span>
              <span class="text-[13px] text-text-muted">
                {{ $t('store.connector.last_heartbeat', { time: dateTime(integration.last_heartbeat_at) }) }}
                <template v-if="integration.connector_version"> · {{ $t('store.connector.version', { version: integration.connector_version }) }}</template>
              </span>
            </div>
            <div class="flex items-center gap-3">
              <StatusBadge :tone="INTEGRATION_TONES[integration.status]" :label="$t(`store.connector.status.${integration.status}`)" />
              <button
                v-if="canManage && integration.status !== 'revoked' && confirmingRevoke !== integration.id"
                type="button"
                class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft"
                @click="confirmingRevoke = integration.id"
              >
                {{ $t('store.connector.revoke') }}
              </button>
            </div>
          </div>
          <div v-if="confirmingRevoke === integration.id" class="flex flex-col gap-3 rounded-md border border-warn-border bg-warn-soft p-3 text-sm">
            <p>{{ $t('store.connector.revoke_warning') }}</p>
            <div class="flex flex-wrap gap-2">
              <button
                type="button"
                class="rounded-md bg-crit-solid px-3 py-1.5 font-medium text-white disabled:opacity-60"
                :disabled="revoking"
                @click="revoke(integration)"
              >
                {{ $t('store.connector.revoke_confirm') }}
              </button>
              <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" @click="confirmingRevoke = null">
                {{ $t('common.cancel') }}
              </button>
            </div>
          </div>
        </li>
      </ul>
    </template>

    <ErrorNotice v-if="actionError" :error="actionError" />

    <div v-if="pairing" class="flex flex-col gap-4 rounded-lg border border-note-border bg-note-soft p-4" data-pairing-code>
      <ol class="list-decimal space-y-1 pl-5 text-sm">
        <li>{{ $t('store.connector.step_install') }}</li>
        <li>{{ $t('store.connector.step_open') }}</li>
        <li>{{ $t('store.connector.step_paste') }}</li>
      </ol>
      <CopyField id="pairing-service-url" :label="$t('store.connector.service_url')" :value="pairing.service_url" />
      <CopyField id="pairing-code" :label="$t('store.connector.pairing_code')" :value="pairing.pairing_code" />
      <p class="text-[13px] text-text-muted">{{ $t('store.connector.code_expires', { time: dateTime(pairing.expires_at) }) }}</p>
      <p class="text-[13px] text-text-muted" role="status">{{ $t('store.connector.waiting') }}</p>
    </div>

    <div v-if="canManage" class="flex flex-wrap items-center gap-3">
      <button
        type="button"
        class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
        :disabled="pairingBusy"
        @click="issueCode"
      >
        {{ pairing ? $t('store.connector.new_code') : liveConnectors.length > 0 ? $t('store.connector.reconnect') : $t('store.connector.get_code') }}
      </button>
      <span class="text-[13px] text-text-muted">{{ $t('store.connector.code_once') }}</span>
    </div>
  </section>
</template>
