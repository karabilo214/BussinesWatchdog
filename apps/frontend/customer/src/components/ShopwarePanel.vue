<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { connectShopware, downloadShopwareApp, syncProvider } from '@/api/providers';
import { listStoreIntegrations, revokeIntegration } from '@/api/stores';
import type { Integration } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import ErrorNotice from './ErrorNotice.vue';
import { INTEGRATION_TONES } from './integrationTones';

const POLL_MS = 15_000;

const props = defineProps<{ storeId: string; canManage: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const { dateTime } = useFormat();

const integration = ref<Integration | null>(null);
const loading = ref(true);
const loadError = ref<unknown>(null);
const method = ref<'app' | 'key'>('app');
const form = reactive({ key: '', secret: '', acknowledged: false });
const busy = ref(false);
const error = ref<unknown>(null);
const notice = ref<string | null>(null);
const confirmingRevoke = ref(false);
let timer: ReturnType<typeof setInterval> | null = null;

const keyValid = computed(() => /^SWIA[A-Za-z0-9]{10,60}$/.test(form.key.trim()));
const secretValid = computed(() => /^[A-Za-z0-9]{20,128}$/.test(form.secret.trim()));
const keyRejected = computed(() => integration.value?.health?.key_rejected_at !== undefined);
const sync = computed(() => integration.value?.health?.sync ?? null);
const viaApp = computed(() => integration.value?.capabilities?.connection === 'app');
const waitingForApp = computed(() => integration.value?.status === 'pending' && viaApp.value);

async function load(): Promise<void> {
  try {
    const all = await listStoreIntegrations(props.storeId);
    const before = integration.value?.status;
    integration.value = all.find((item) => item.provider === 'shopware' && ['active', 'degraded', 'pending'].includes(item.status)) ?? null;
    loadError.value = null;

    if (before === 'pending' && integration.value?.status === 'active') {
      notice.value = 'store.shopware.app_connected';
      emit('changed');
    }
  } catch (caught) {
    loadError.value = caught;
  } finally {
    loading.value = false;
  }
}

async function act(action: () => Promise<void>, success: string | null = null): Promise<void> {
  busy.value = true;
  error.value = null;
  notice.value = null;

  try {
    await action();
    notice.value = success;
  } catch (caught) {
    error.value = caught;
    await load();
  } finally {
    busy.value = false;
  }
}

function download(): Promise<void> {
  return act(async () => {
    const file = await downloadShopwareApp(props.storeId);
    const url = URL.createObjectURL(file.blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = file.filename ?? 'BusinessWatchdog.zip';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
    await load();
  }, 'store.shopware.app_downloaded');
}

function connect(): Promise<void> {
  return act(async () => {
    integration.value = await connectShopware(props.storeId, { access_key: form.key.trim(), secret_access_key: form.secret.trim(), state_change_risk_acknowledged: form.acknowledged });
    form.key = '';
    form.secret = '';
    form.acknowledged = false;
    emit('changed');
  }, 'store.shopware.connected');
}

function runSync(): Promise<void> {
  return act(async () => {
    integration.value = (await syncProvider(integration.value!.id)).integration;
  });
}

function revoke(): Promise<void> {
  return act(async () => {
    await revokeIntegration(integration.value!.id);
    confirmingRevoke.value = false;
    integration.value = null;
    emit('changed');
  }, 'store.shopware.revoked');
}

watch(waitingForApp, (waiting) => {
  if (waiting && timer === null) {
    timer = setInterval(load, POLL_MS);
  } else if (!waiting && timer !== null) {
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
  <div class="flex flex-col gap-4" data-connector="shopware">
    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <ErrorNotice v-else-if="loadError" :error="loadError" />

    <div v-else-if="waitingForApp" class="flex flex-col gap-3 rounded-lg border border-note-border bg-note-soft p-4 text-sm" data-app-waiting>
      <p class="font-medium">{{ $t('store.shopware.app_waiting') }}</p>
      <ol class="list-decimal space-y-1 pl-5">
        <li>{{ $t('store.shopware.app_step_upload') }}</li>
        <li>{{ $t('store.shopware.app_step_install') }}</li>
      </ol>
      <p v-if="integration?.health?.app_check_error" class="text-warn">{{ $t('store.shopware.app_not_active_yet') }}</p>
      <p class="text-[13px] text-text-muted" role="status">{{ $t('store.shopware.app_auto_refresh') }}</p>
      <div v-if="canManage" class="flex flex-wrap gap-2">
        <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy" @click="download">{{ $t('store.shopware.app_download_again') }}</button>
        <button v-if="!confirmingRevoke" type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium text-crit hover:bg-crit-soft" @click="confirmingRevoke = true">{{ $t('common.cancel') }}</button>
      </div>
      <div v-if="confirmingRevoke" class="flex gap-2">
        <button type="button" class="rounded-md bg-crit-solid px-3 py-1.5 font-medium text-white disabled:opacity-60" :disabled="busy" @click="revoke">{{ $t('store.shopware.app_cancel_confirm') }}</button>
        <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" @click="confirmingRevoke = false">{{ $t('common.back') }}</button>
      </div>
    </div>

    <template v-else-if="integration">
      <p v-if="keyRejected" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit" data-key-rejected>{{ $t('store.shopware.key_rejected') }}</p>

      <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[13rem_1fr]">
        <dt class="text-text-muted">{{ $t('store.api.state') }}</dt>
        <dd><StatusBadge :tone="INTEGRATION_TONES[integration.status]" :label="$t(`store.connector.status.${integration.status}`)" /></dd>
        <dt class="text-text-muted">{{ $t('store.shopware.connection') }}</dt>
        <dd data-connection>{{ viaApp ? $t('store.shopware.connection_app') : $t('store.shopware.connection_key') }} · <span class="font-mono text-[13px]">SWIA••••{{ integration.health?.key_last4 ?? '' }}</span></dd>
        <dt class="text-text-muted">{{ $t('store.api.last_sync') }}</dt>
        <dd data-last-sync>
          {{ dateTime(integration.last_successful_sync_at ?? null) }}
          <span v-if="sync" class="block text-[13px] text-text-muted">
            {{ $t('provider.sync_summary', { emitted: sync.emitted ?? 0, unchanged: sync.unchanged ?? 0 }) }}<template v-if="sync.complete === false"> · {{ $t('provider.sync_incomplete') }}</template>
          </span>
        </dd>
        <template v-if="viaApp">
          <dt class="text-text-muted">{{ $t('provider.webhook') }}</dt>
          <dd>{{ integration.health?.webhook?.last_received_at ? $t('provider.webhook_last', { time: dateTime(integration.health.webhook.last_received_at) }) : $t('store.shopware.no_webhook_yet') }}</dd>
        </template>
      </dl>
      <p v-if="integration.health?.last_error && !keyRejected" class="text-sm text-warn" data-last-error>
        {{ $t('provider.last_error', { time: dateTime(integration.health.last_error.at) }) }} {{ $te(`errors.${integration.health.last_error.code}`) ? $t(`errors.${integration.health.last_error.code}`) : integration.health.last_error.code }}
      </p>
      <ul class="flex flex-col gap-1 text-[13px] text-text-muted" data-shopware-limits>
        <li>{{ viaApp ? $t('store.shopware.app_realtime_note') : $t('store.api.polling_note') }}</li>
        <li>{{ $t('store.shopware.refunds_note') }}</li>
        <li>{{ $t('store.shopware.state_risk_short') }}</li>
        <li>{{ $t('store.shopware.stripe_note') }}</li>
      </ul>

      <div v-if="canManage" class="flex flex-wrap gap-2">
        <button v-if="integration.status === 'active'" type="button" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy" @click="runSync">{{ $t('provider.sync_now') }}</button>
        <button v-if="!confirmingRevoke" type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft" @click="confirmingRevoke = true">{{ $t('store.connector.revoke') }}</button>
      </div>

      <div v-if="confirmingRevoke" class="flex flex-col gap-2 rounded-md border border-warn-border bg-warn-soft p-3 text-sm">
        <p>{{ viaApp ? $t('store.shopware.app_revoke_warning') : $t('store.shopware.revoke_warning') }}</p>
        <div class="flex gap-2">
          <button type="button" class="rounded-md bg-crit-solid px-3 py-1.5 font-medium text-white disabled:opacity-60" :disabled="busy" @click="revoke">{{ $t('store.connector.revoke_confirm') }}</button>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" @click="confirmingRevoke = false">{{ $t('common.cancel') }}</button>
        </div>
      </div>
    </template>

    <template v-else>
      <p class="text-sm">{{ $t('store.shopware.why') }}</p>
      <div v-if="canManage" class="flex flex-col gap-4">
        <fieldset class="flex flex-col gap-2">
          <legend class="mb-1 text-sm font-medium">{{ $t('store.shopware.method') }}</legend>
          <label class="flex items-start gap-2 text-sm">
            <input v-model="method" type="radio" value="app" class="mt-0.5 size-4 accent-primary" data-method="app" />
            <span><span class="font-medium">{{ $t('store.shopware.method_app') }}</span> <StatusBadge tone="note" :label="$t('store.order_source.recommended')" /><span class="block text-[13px] text-text-muted">{{ $t('store.shopware.method_app_hint') }}</span></span>
          </label>
          <label class="flex items-start gap-2 text-sm">
            <input v-model="method" type="radio" value="key" class="mt-0.5 size-4 accent-primary" data-method="key" />
            <span><span class="font-medium">{{ $t('store.shopware.method_key') }}</span><span class="block text-[13px] text-text-muted">{{ $t('store.shopware.method_key_hint') }}</span></span>
          </label>
        </fieldset>

        <div class="flex flex-col gap-2 rounded-md border border-warn-border bg-warn-soft p-3 text-sm" data-state-risk>
          <p>{{ $t('store.shopware.state_risk') }}</p>
          <label class="flex items-start gap-2 font-medium">
            <input v-model="form.acknowledged" type="checkbox" class="mt-0.5 size-4 accent-primary" data-acknowledge />
            {{ $t('store.shopware.acknowledge') }}
          </label>
        </div>

        <div v-if="method === 'app'" class="flex flex-col gap-3" data-app-steps>
          <ol class="list-decimal space-y-1 pl-5 text-sm">
            <li>{{ $t('store.shopware.app_step_download') }}</li>
            <li>{{ $t('store.shopware.app_step_upload') }}</li>
            <li>{{ $t('store.shopware.app_step_install') }}</li>
          </ol>
          <p class="text-[13px] text-text-muted">{{ $t('store.shopware.app_permissions') }}</p>
          <p class="text-[13px] text-warn" data-app-version-note>{{ $t('store.shopware.app_version_note') }}</p>
          <div>
            <button type="button" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !form.acknowledged" data-download-app @click="download">{{ $t('store.shopware.app_download') }}</button>
          </div>
        </div>

        <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="connect">
          <ol class="list-decimal space-y-1 pl-5 text-sm">
            <li>{{ $t('store.shopware.step_role') }}</li>
            <li>{{ $t('store.shopware.step_integration') }}</li>
            <li>{{ $t('store.shopware.step_paste') }}</li>
          </ol>
          <p class="text-[13px] text-text-muted">{{ $t('store.shopware.requirements') }}</p>
          <div class="grid gap-3 sm:grid-cols-2">
            <div class="flex flex-col gap-1.5">
              <label for="sw-access-key" class="text-sm font-medium">{{ $t('store.shopware.key_label') }}</label>
              <input id="sw-access-key" v-model="form.key" autocomplete="off" spellcheck="false" placeholder="SWIA…" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm" />
              <p v-if="form.key && !keyValid" class="text-[13px] text-crit">{{ $t('store.shopware.key_hint') }}</p>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="sw-secret" class="text-sm font-medium">{{ $t('store.shopware.secret_label') }}</label>
              <input id="sw-secret" v-model="form.secret" type="password" autocomplete="off" spellcheck="false" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm" />
            </div>
          </div>
          <div>
            <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !keyValid || !secretValid || !form.acknowledged">{{ $t('store.shopware.connect') }}</button>
          </div>
        </form>

        <details class="rounded-lg border border-border p-3 text-sm" data-shopware-access>
          <summary class="cursor-pointer font-medium">{{ $t('store.api.access_title') }}</summary>
          <ul class="mt-2 flex list-disc flex-col gap-1 pl-5">
            <li>{{ $t('store.shopware.access_reads') }}</li>
            <li>{{ $t('store.shopware.access_not_read') }}</li>
            <li>{{ $t('store.shopware.access_calls') }}</li>
            <li>{{ $t('store.api.access_revoke') }}</li>
          </ul>
        </details>
        <ul class="flex flex-col gap-1 text-[13px] text-text-muted">
          <li>{{ $t('store.api.verifies_store') }}</li>
          <li>{{ $t('store.shopware.refunds_note') }}</li>
          <li data-stripe-note>{{ $t('store.shopware.stripe_note') }}</li>
        </ul>
      </div>
    </template>

    <p v-if="notice" class="text-sm text-ok" role="status">{{ $t(notice) }}</p>
    <ErrorNotice v-if="error" :error="error" />
  </div>
</template>
