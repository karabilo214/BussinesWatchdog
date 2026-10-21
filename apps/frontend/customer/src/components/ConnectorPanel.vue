<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { createPairingCode, listStoreIntegrations, revokeIntegration } from '@/api/stores';
import type { Integration, PairingCode } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import CopyField from './CopyField.vue';
import ErrorNotice from './ErrorNotice.vue';
import ObservationTexts from './ObservationTexts.vue';
import PluginDownload from './PluginDownload.vue';
import { INTEGRATION_TONES, providerName } from './integrationTones';

const POLL_MS = 15_000;

const props = withDefaults(defineProps<{ storeId: string; canManage: boolean; apiActive?: boolean }>(), { apiActive: false });
const emit = defineEmits<{ changed: [] }>();
const { dateTime } = useFormat();

const integrations = ref<Integration[]>([]);
const loading = ref(true);
const loadError = ref<unknown>(null);
const pairing = ref<PairingCode | null>(null);
const pairingBusy = ref(false);
const installOpen = ref(false);
const actionError = ref<unknown>(null);
const confirmingRevoke = ref<string | null>(null);
const revoking = ref(false);
let timer: ReturnType<typeof setInterval> | null = null;

const connectors = computed(() => integrations.value.filter((integration) => integration.provider === 'woocommerce'));
const liveConnectors = computed(() => connectors.value.filter((integration) => integration.status !== 'revoked' && integration.status !== 'disabled'));

async function load(): Promise<void> {
  try {
    const before = liveConnectors.value.length;
    integrations.value = await listStoreIntegrations(props.storeId);
    loadError.value = null;

    if (pairing.value !== null && liveConnectors.value.length > before) {
      pairing.value = null;
      installOpen.value = false;
      emit('changed');
    }
  } catch (error) {
    loadError.value = error;
  } finally {
    loading.value = false;
  }
}

async function issueCode(): Promise<void> {
  installOpen.value = true;
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

function beginInstall(): void {
  installOpen.value = true;
  if (props.canManage && !props.apiActive) void issueCode();
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
  <div class="flex flex-col gap-4" data-connector="plugin">
    <p class="max-w-3xl text-sm text-text-muted">{{ $t('store.connector.subtitle') }}</p>
    <PluginDownload @downloaded="beginInstall" />
    <ObservationTexts />

    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <ErrorNotice v-else-if="loadError" :error="loadError" />

    <template v-else>
      <p v-if="connectors.length === 0 && apiActive" class="text-sm" data-other-source>{{ $t('store.connector.api_active') }}</p>
      <p v-else-if="connectors.length === 0" class="text-sm text-text-muted">{{ $t('store.connector.none') }}</p>

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

    <div v-if="canManage && !apiActive" class="flex flex-wrap items-center gap-3">
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

    <div v-if="installOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @keydown.esc="installOpen = false">
      <div role="dialog" aria-modal="true" aria-labelledby="woo-install-title" class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-surface p-5 shadow-xl" data-install-dialog>
        <div class="mb-4 flex items-start justify-between gap-4">
          <h2 id="woo-install-title" class="text-lg font-semibold">{{ $t('store.connector.install_title') }}</h2>
          <button type="button" class="rounded-md border border-border px-3 py-1 text-sm" @click="installOpen = false">{{ $t('store.connector.close_guide') }}</button>
        </div>
        <ol class="list-decimal space-y-2 pl-5 text-sm">
          <li>{{ $t('store.connector.step_download') }}</li>
          <li>{{ $t('store.connector.step_upload') }}</li>
          <li>{{ $t('store.connector.step_install') }}</li>
          <li>{{ $t('store.connector.step_open') }}</li>
          <li>{{ $t('store.connector.step_paste') }}</li>
        </ol>
        <div v-if="pairing" class="mt-4 flex flex-col gap-3 rounded-lg border border-note-border bg-note-soft p-4" data-pairing-code>
          <CopyField id="pairing-service-url" :label="$t('store.connector.service_url')" :value="pairing.service_url" />
          <CopyField id="pairing-code" :label="$t('store.connector.pairing_code')" :value="pairing.pairing_code" />
          <p class="text-[13px] text-text-muted">{{ $t('store.connector.code_expires', { time: dateTime(pairing.expires_at) }) }}</p>
          <p class="text-[13px] text-text-muted" role="status">{{ $t('store.connector.waiting') }}</p>
          <button v-if="canManage && !apiActive" type="button" class="self-start rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm" :disabled="pairingBusy" @click="issueCode">{{ $t('store.connector.new_code') }}</button>
        </div>
        <p v-else-if="pairingBusy" class="mt-4 text-sm" role="status">{{ $t('store.connector.creating_code') }}</p>
        <p v-else-if="apiActive" class="mt-4 text-sm text-warn">{{ $t('store.connector.api_active') }}</p>
        <button v-else-if="canManage" type="button" class="mt-4 rounded-md bg-primary px-4 py-2 text-sm text-text-inverse" @click="issueCode">{{ $t('store.connector.new_code') }}</button>
        <ErrorNotice v-if="actionError" :error="actionError" />
      </div>
    </div>
  </div>
</template>
