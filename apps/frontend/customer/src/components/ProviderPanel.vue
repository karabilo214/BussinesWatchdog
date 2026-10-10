<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { connectStripe, setWebhookSecret, syncProvider, webhookUrl } from '@/api/providers';
import { listStoreIntegrations, revokeIntegration } from '@/api/stores';
import type { Integration } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import CopyField from './CopyField.vue';
import ErrorNotice from './ErrorNotice.vue';
import { INTEGRATION_TONES } from './integrationTones';

const props = defineProps<{ storeId: string; canManage: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const { dateTime } = useFormat();

const integration = ref<Integration | null>(null);
const loading = ref(true);
const loadError = ref<unknown>(null);
const form = reactive({ key: '', webhook: '', account: '' });
const webhookForm = ref<string | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const notice = ref<string | null>(null);
const confirmingRevoke = ref(false);

const keyRejected = computed(() => integration.value?.status === 'degraded' && integration.value.health?.last_error?.code === 'stripe_key_rejected');
const webhookConfigured = computed(() => (integration.value?.credentials ?? []).some((credential) => credential.kind === 'stripe_webhook' && credential.status === 'active'));
const keyLooksRestricted = computed(() => /^rk_(test|live)_[A-Za-z0-9]{10,}$/.test(form.key.trim()));
const sync = computed(() => integration.value?.health?.sync ?? null);

async function load(): Promise<void> {
  try {
    const all = await listStoreIntegrations(props.storeId);
    integration.value = all.find((item) => item.provider === 'stripe' && ['active', 'degraded', 'pending'].includes(item.status)) ?? null;
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
    integration.value = await connectStripe(props.storeId, {
      restricted_api_key: form.key.trim(),
      ...(form.webhook.trim() ? { webhook_secret: form.webhook.trim() } : {}),
      ...(form.account.trim() ? { expected_account_id: form.account.trim() } : {}),
    });
    form.key = '';
    form.webhook = '';
    emit('changed');
  }, 'provider.connected');
}

function runSync(): Promise<void> {
  return act(async () => {
    const result = await syncProvider(integration.value!.id);
    integration.value = result.integration;
    notice.value = null;
  });
}

function saveWebhook(): Promise<void> {
  return act(async () => {
    integration.value = await setWebhookSecret(integration.value!.id, (webhookForm.value ?? '').trim());
    webhookForm.value = null;
  }, 'provider.webhook_saved');
}

function revoke(): Promise<void> {
  return act(async () => {
    await revokeIntegration(integration.value!.id);
    confirmingRevoke.value = false;
    integration.value = null;
    emit('changed');
  }, 'provider.revoked');
}

void load();
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="provider-title" data-panel="provider">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex flex-col gap-1">
        <h2 id="provider-title" class="text-lg font-semibold">{{ $t('provider.title') }}</h2>
        <p class="max-w-3xl text-sm text-text-muted">{{ $t('provider.subtitle') }}</p>
      </div>
      <StatusBadge v-if="integration" :tone="INTEGRATION_TONES[integration.status]" :label="`Stripe · ${$t(`provider.status.${integration.status}`)}`" />
      <StatusBadge v-else-if="!loading" tone="unknown" :label="$t('provider.not_connected')" />
    </div>

    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <ErrorNotice v-else-if="loadError" :error="loadError" />

    <template v-else-if="integration">
      <p v-if="keyRejected" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit" data-key-rejected>{{ $t('provider.key_rejected') }}</p>

      <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[13rem_1fr]">
        <dt class="text-text-muted">{{ $t('provider.mode') }}</dt>
        <dd><StatusBadge :tone="integration.mode === 'live' ? 'ok' : 'note'" :label="$t(`provider.modes.${integration.mode}`)" /></dd>
        <dt class="text-text-muted">{{ $t('provider.account') }}</dt>
        <dd class="font-mono text-[13px]">{{ integration.external_account_id ?? '—' }}<span class="ml-2 font-sans text-text-muted">{{ integration.health?.account_verified ? $t('provider.account_verified') : $t('provider.account_unverified') }}</span></dd>
        <dt class="text-text-muted">{{ $t('provider.key') }}</dt>
        <dd class="font-mono text-[13px]">rk_••••{{ integration.health?.key_last4 ?? '' }}</dd>
        <dt class="text-text-muted">{{ $t('provider.last_sync') }}</dt>
        <dd data-last-sync>
          {{ dateTime(integration.last_successful_sync_at ?? null) }}
          <span v-if="sync" class="block text-[13px] text-text-muted">
            {{ $t('provider.sync_summary', { emitted: sync.emitted ?? 0, unchanged: sync.unchanged ?? 0 }) }}<template v-if="sync.complete === false"> · {{ $t('provider.sync_incomplete') }}</template>
          </span>
        </dd>
        <dt class="text-text-muted">{{ $t('provider.webhook') }}</dt>
        <dd>
          {{ webhookConfigured ? $t('provider.webhook_on') : $t('provider.webhook_off') }}
          <span v-if="integration.health?.webhook?.last_received_at" class="block text-[13px] text-text-muted">{{ $t('provider.webhook_last', { time: dateTime(integration.health.webhook.last_received_at) }) }}</span>
        </dd>
      </dl>
      <p v-if="integration.health?.last_error && !keyRejected" class="text-sm text-warn" data-last-error>
        {{ $t('provider.last_error', { time: dateTime(integration.health.last_error.at) }) }} {{ $te(`provider.errors.${integration.health.last_error.code}`) ? $t(`provider.errors.${integration.health.last_error.code}`) : integration.health.last_error.code }}
      </p>
      <p class="text-[13px] text-text-muted">{{ $t('provider.polling_note') }}</p>

      <div v-if="canManage" class="flex flex-wrap gap-2">
        <button v-if="integration.status === 'active'" type="button" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy" @click="runSync">{{ $t('provider.sync_now') }}</button>
        <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft" @click="webhookForm = webhookForm === null ? '' : null">{{ $t('provider.webhook_secret') }}</button>
        <button v-if="!confirmingRevoke" type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft" @click="confirmingRevoke = true">{{ $t('provider.revoke') }}</button>
      </div>

      <div v-if="confirmingRevoke" class="flex flex-col gap-2 rounded-md border border-warn-border bg-warn-soft p-3 text-sm">
        <p>{{ $t('provider.revoke_warning') }}</p>
        <div class="flex gap-2">
          <button type="button" class="rounded-md bg-crit-solid px-3 py-1.5 font-medium text-white disabled:opacity-60" :disabled="busy" @click="revoke">{{ $t('provider.revoke_confirm') }}</button>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" @click="confirmingRevoke = false">{{ $t('common.cancel') }}</button>
        </div>
      </div>

      <form v-if="webhookForm !== null" class="flex flex-col gap-3 rounded-lg border border-border p-4" @submit.prevent="saveWebhook">
        <ol class="list-decimal space-y-1 pl-5 text-sm">
          <li>{{ $t('provider.webhook_step_add') }}</li>
          <li>{{ $t('provider.webhook_step_events') }}</li>
          <li>{{ $t('provider.webhook_step_secret') }}</li>
        </ol>
        <CopyField id="stripe-webhook-url" :label="$t('provider.webhook_url')" :value="integration.webhook_url ?? webhookUrl(integration.id)" />
        <label for="stripe-webhook-secret" class="text-sm font-medium">{{ $t('provider.webhook_secret_label') }}</label>
        <input id="stripe-webhook-secret" v-model="webhookForm" type="password" autocomplete="off" spellcheck="false" placeholder="whsec_…" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm sm:max-w-md" />
        <div>
          <button type="submit" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !/^whsec_[A-Za-z0-9]{10,}$/.test((webhookForm ?? '').trim())">{{ $t('common.save') }}</button>
        </div>
      </form>
    </template>

    <template v-else>
      <p class="text-sm">{{ $t('provider.why') }}</p>
      <form v-if="canManage" class="flex flex-col gap-4" novalidate @submit.prevent="connect">
        <ol class="list-decimal space-y-1 pl-5 text-sm">
          <li>{{ $t('provider.step_dashboard') }}</li>
          <li>{{ $t('provider.step_permissions') }}</li>
          <li>{{ $t('provider.step_paste') }}</li>
        </ol>
        <div class="flex flex-col gap-1.5">
          <label for="stripe-key" class="text-sm font-medium">{{ $t('provider.key_label') }}</label>
          <input id="stripe-key" v-model="form.key" type="password" autocomplete="off" spellcheck="false" placeholder="rk_live_…" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm" />
          <p class="text-[13px]" :class="form.key && !keyLooksRestricted ? 'text-crit' : 'text-text-muted'">{{ form.key.trim().startsWith('sk_') ? $t('provider.secret_key_warning') : $t('provider.key_hint') }}</p>
        </div>
        <details class="rounded-lg border border-border p-3 text-sm">
          <summary class="cursor-pointer font-medium">{{ $t('checks.scenario.advanced') }}</summary>
          <div class="mt-3 grid gap-3 sm:grid-cols-2">
            <label class="flex flex-col gap-1.5 font-medium">
              {{ $t('provider.webhook_secret_label') }}
              <input v-model="form.webhook" type="password" autocomplete="off" spellcheck="false" placeholder="whsec_…" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono font-normal" />
            </label>
            <label class="flex flex-col gap-1.5 font-medium">
              {{ $t('provider.expected_account') }}
              <input v-model="form.account" spellcheck="false" placeholder="acct_…" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono font-normal" />
            </label>
          </div>
        </details>
        <p class="text-[13px] text-text-muted">{{ $t('provider.storage_note') }}</p>
        <div>
          <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !keyLooksRestricted">{{ $t('provider.connect') }}</button>
        </div>
      </form>
    </template>

    <p v-if="notice" class="text-sm text-ok" role="status">{{ $t(notice) }}</p>
    <ErrorNotice v-if="error" :error="error" />
  </section>
</template>
