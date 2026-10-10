<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { connectPayPal, setPayPalWebhookId, syncProvider } from '@/api/providers';
import { listStoreIntegrations, revokeIntegration } from '@/api/stores';
import type { Integration } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import CopyField from './CopyField.vue';
import ErrorNotice from './ErrorNotice.vue';

const props = defineProps<{ storeId: string; canManage: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const { dateTime } = useFormat();

const integration = ref<Integration | null>(null);
const loading = ref(true);
const loadError = ref<unknown>(null);
const form = reactive({ environment: 'live' as 'live' | 'sandbox', clientId: '', clientSecret: '', webhookId: '', acknowledged: false });
const webhookForm = ref<string | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const notice = ref<string | null>(null);
const confirmingRevoke = ref(false);

const credentialsRejected = computed(() => integration.value?.status === 'degraded' && ['paypal_credentials_rejected', 'paypal_permission_missing'].includes(integration.value.health?.last_error?.code ?? ''));
const webhookConfigured = computed(() => (integration.value?.credentials ?? []).some((credential) => credential.kind === 'paypal_webhook' && credential.status === 'active'));
const sync = computed(() => integration.value?.health?.sync ?? null);
const writeScopes = computed(() => integration.value?.health?.write_scopes ?? []);
const formReady = computed(() => /^[A-Za-z0-9_-]{20,128}$/.test(form.clientId.trim()) && /^[A-Za-z0-9_-]{20,128}$/.test(form.clientSecret.trim()) && form.acknowledged);

async function load(): Promise<void> {
  try {
    const all = await listStoreIntegrations(props.storeId);
    integration.value = all.find((item) => item.provider === 'paypal' && ['active', 'degraded', 'pending'].includes(item.status)) ?? null;
    loadError.value = null;
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

function connect(): Promise<void> {
  return act(async () => {
    integration.value = await connectPayPal(props.storeId, {
      environment: form.environment,
      client_id: form.clientId.trim(),
      client_secret: form.clientSecret.trim(),
      ...(form.webhookId.trim() ? { webhook_id: form.webhookId.trim() } : {}),
      write_access_acknowledged: form.acknowledged,
    });
    form.clientSecret = '';
    form.clientId = '';
    form.webhookId = '';
    form.acknowledged = false;
    emit('changed');
  }, 'paypal.connected');
}

function runSync(): Promise<void> {
  return act(async () => {
    const result = await syncProvider(integration.value!.id);
    integration.value = result.integration;
  });
}

function saveWebhook(): Promise<void> {
  return act(async () => {
    integration.value = await setPayPalWebhookId(integration.value!.id, (webhookForm.value ?? '').trim());
    webhookForm.value = null;
  }, 'paypal.webhook_saved');
}

function revoke(): Promise<void> {
  return act(async () => {
    await revokeIntegration(integration.value!.id);
    confirmingRevoke.value = false;
    integration.value = null;
    emit('changed');
  }, 'paypal.revoked');
}

void load();
</script>

<template>
  <div class="flex flex-col gap-4" data-provider="paypal">

    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <ErrorNotice v-else-if="loadError" :error="loadError" />

    <template v-else-if="integration">
      <p v-if="credentialsRejected" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit" data-key-rejected>{{ $t('paypal.credentials_rejected') }}</p>

      <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[13rem_1fr]">
        <dt class="text-text-muted">{{ $t('provider.mode') }}</dt>
        <dd><StatusBadge :tone="integration.mode === 'live' ? 'ok' : 'note'" :label="$t(`paypal.environments.${integration.mode === 'live' ? 'live' : 'sandbox'}`)" /></dd>
        <dt class="text-text-muted">{{ $t('paypal.app') }}</dt>
        <dd class="font-mono text-[13px]">{{ integration.external_account_id ?? '—' }} · ••••{{ integration.health?.client_id_last4 ?? '' }}</dd>
        <dt class="text-text-muted">{{ $t('paypal.rights') }}</dt>
        <dd data-write-scopes>
          <span class="text-warn">{{ writeScopes.length > 0 ? writeScopes.map((scope) => $te(`paypal.scopes.${scope}`) ? $t(`paypal.scopes.${scope}`) : scope).join(', ') : '—' }}</span>
          <span class="block text-[13px] text-text-muted">{{ $t('paypal.rights_note') }}</span>
        </dd>
        <dt class="text-text-muted">{{ $t('provider.last_sync') }}</dt>
        <dd data-last-sync>
          {{ dateTime(integration.last_successful_sync_at ?? null) }}
          <span v-if="sync" class="block text-[13px] text-text-muted">
            {{ $t('provider.sync_summary', { emitted: sync.emitted ?? 0, unchanged: sync.unchanged ?? 0 }) }}<template v-if="sync.complete === false"> · {{ $t('provider.sync_incomplete') }}</template>
          </span>
          <span v-if="sync?.search_refreshed_at" class="block text-[13px] text-text-muted">{{ $t('paypal.search_refreshed', { time: dateTime(sync.search_refreshed_at) }) }}</span>
        </dd>
        <dt class="text-text-muted">{{ $t('provider.webhook') }}</dt>
        <dd>
          {{ webhookConfigured ? $t('provider.webhook_on') : $t('paypal.webhook_off') }}
          <span v-if="integration.health?.webhook?.last_received_at" class="block text-[13px] text-text-muted">{{ $t('provider.webhook_last', { time: dateTime(integration.health.webhook.last_received_at) }) }}</span>
        </dd>
      </dl>
      <p v-if="integration.health?.last_error && !credentialsRejected" class="text-sm text-warn" data-last-error>
        {{ $t('provider.last_error', { time: dateTime(integration.health.last_error.at) }) }} {{ $te(`errors.${integration.health.last_error.code}`) ? $t(`errors.${integration.health.last_error.code}`) : integration.health.last_error.code }}
      </p>
      <p class="text-[13px] text-text-muted">{{ $t('paypal.polling_note') }}</p>
      <p class="text-[13px] text-text-muted" data-matching-note>{{ $t('paypal.matching_note') }}</p>

      <div v-if="canManage" class="flex flex-wrap gap-2">
        <button v-if="integration.status === 'active'" type="button" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy" @click="runSync">{{ $t('provider.sync_now') }}</button>
        <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft" @click="webhookForm = webhookForm === null ? '' : null">{{ $t('paypal.webhook_id') }}</button>
        <button v-if="!confirmingRevoke" type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft" @click="confirmingRevoke = true">{{ $t('provider.revoke') }}</button>
      </div>

      <div v-if="confirmingRevoke" class="flex flex-col gap-2 rounded-md border border-warn-border bg-warn-soft p-3 text-sm">
        <p>{{ $t('paypal.revoke_warning') }}</p>
        <div class="flex gap-2">
          <button type="button" class="rounded-md bg-crit-solid px-3 py-1.5 font-medium text-white disabled:opacity-60" :disabled="busy" @click="revoke">{{ $t('paypal.revoke_confirm') }}</button>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" @click="confirmingRevoke = false">{{ $t('common.cancel') }}</button>
        </div>
      </div>

      <form v-if="webhookForm !== null" class="flex flex-col gap-3 rounded-lg border border-border p-4" data-webhook-form @submit.prevent="saveWebhook">
        <ol class="list-decimal space-y-1 pl-5 text-sm">
          <li>{{ $t('paypal.webhook_step_add') }}</li>
          <li>{{ $t('paypal.webhook_step_events') }}</li>
          <li>{{ $t('paypal.webhook_step_id') }}</li>
        </ol>
        <CopyField id="paypal-webhook-url" :label="$t('provider.webhook_url')" :value="integration.webhook_url ?? ''" />
        <label for="paypal-webhook-id" class="text-sm font-medium">Webhook ID</label>
        <input id="paypal-webhook-id" v-model="webhookForm" autocomplete="off" spellcheck="false" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm sm:max-w-md" />
        <div>
          <button type="submit" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !/^[A-Z0-9]{10,40}$/.test((webhookForm ?? '').trim())">{{ $t('common.save') }}</button>
        </div>
      </form>
    </template>

    <template v-else>
      <p class="text-sm">{{ $t('paypal.why') }}</p>
      <form v-if="canManage" class="flex flex-col gap-4" novalidate @submit.prevent="connect">
        <ol class="list-decimal space-y-1 pl-5 text-sm">
          <li>{{ $t('paypal.step_app') }}</li>
          <li>{{ $t('paypal.step_features') }}</li>
          <li>{{ $t('paypal.step_copy') }}</li>
        </ol>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="flex flex-col gap-1.5 text-sm font-medium">
            {{ $t('paypal.environment') }}
            <select v-model="form.environment" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal">
              <option value="live">{{ $t('paypal.environments.live') }}</option>
              <option value="sandbox">{{ $t('paypal.environments.sandbox') }}</option>
            </select>
          </label>
          <label class="flex flex-col gap-1.5 text-sm font-medium sm:col-start-1">
            Client ID
            <input id="paypal-client-id" v-model="form.clientId" autocomplete="off" spellcheck="false" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm font-normal" />
          </label>
          <label class="flex flex-col gap-1.5 text-sm font-medium">
            Secret
            <input id="paypal-client-secret" v-model="form.clientSecret" type="password" autocomplete="off" spellcheck="false" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm font-normal" />
          </label>
        </div>
        <details class="rounded-lg border border-border p-3 text-sm">
          <summary class="cursor-pointer font-medium">{{ $t('checks.scenario.advanced') }}</summary>
          <label class="mt-3 flex flex-col gap-1.5 font-medium sm:max-w-md">
            Webhook ID
            <input v-model="form.webhookId" autocomplete="off" spellcheck="false" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono font-normal" />
          </label>
        </details>
        <div class="flex flex-col gap-2 rounded-md border border-warn-border bg-warn-soft p-3 text-sm" data-write-warning>
          <p class="font-semibold">{{ $t('paypal.warning_title') }}</p>
          <p>{{ $t('paypal.warning_body') }}</p>
          <label class="flex items-start gap-2 font-medium">
            <input v-model="form.acknowledged" type="checkbox" class="mt-0.5 size-4 accent-primary" data-acknowledge />
            {{ $t('paypal.acknowledge') }}
          </label>
        </div>
        <p class="text-[13px] text-text-muted">{{ $t('paypal.storage_note') }}</p>
        <p class="text-[13px] text-text-muted">{{ $t('paypal.matching_note') }}</p>
        <div>
          <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !formReady">{{ $t('paypal.connect') }}</button>
        </div>
      </form>
    </template>

    <p v-if="notice" class="text-sm text-ok" role="status">{{ $t(notice) }}</p>
    <ErrorNotice v-if="error" :error="error" />
  </div>
</template>
