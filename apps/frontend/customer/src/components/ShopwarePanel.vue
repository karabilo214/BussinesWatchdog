<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { connectShopware, syncProvider } from '@/api/providers';
import { listStoreIntegrations, revokeIntegration } from '@/api/stores';
import type { Integration } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import ErrorNotice from './ErrorNotice.vue';
import { INTEGRATION_TONES } from './integrationTones';

const props = defineProps<{ storeId: string; canManage: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const { dateTime } = useFormat();

const integration = ref<Integration | null>(null);
const loading = ref(true);
const loadError = ref<unknown>(null);
const form = reactive({ key: '', secret: '', acknowledged: false });
const busy = ref(false);
const error = ref<unknown>(null);
const notice = ref<string | null>(null);
const confirmingRevoke = ref(false);

const keyValid = computed(() => /^SWIA[A-Za-z0-9]{10,60}$/.test(form.key.trim()));
const secretValid = computed(() => /^[A-Za-z0-9]{20,128}$/.test(form.secret.trim()));
const keyRejected = computed(() => integration.value?.health?.key_rejected_at !== undefined);
const sync = computed(() => integration.value?.health?.sync ?? null);

async function load(): Promise<void> {
  try {
    const all = await listStoreIntegrations(props.storeId);
    integration.value = all.find((item) => item.provider === 'shopware' && ['active', 'degraded', 'pending'].includes(item.status)) ?? null;
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

void load();
</script>

<template>
  <div class="flex flex-col gap-4" data-connector="shopware">
    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <ErrorNotice v-else-if="loadError" :error="loadError" />

    <template v-else-if="integration">
      <p v-if="keyRejected" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit" data-key-rejected>{{ $t('store.shopware.key_rejected') }}</p>

      <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[13rem_1fr]">
        <dt class="text-text-muted">{{ $t('store.api.state') }}</dt>
        <dd><StatusBadge :tone="INTEGRATION_TONES[integration.status]" :label="$t(`store.connector.status.${integration.status}`)" /></dd>
        <dt class="text-text-muted">{{ $t('store.shopware.key') }}</dt>
        <dd class="font-mono text-[13px]">SWIA••••{{ integration.health?.key_last4 ?? '' }}</dd>
        <dt class="text-text-muted">{{ $t('store.api.last_sync') }}</dt>
        <dd data-last-sync>
          {{ dateTime(integration.last_successful_sync_at ?? null) }}
          <span v-if="sync" class="block text-[13px] text-text-muted">
            {{ $t('provider.sync_summary', { emitted: sync.emitted ?? 0, unchanged: sync.unchanged ?? 0 }) }}<template v-if="sync.complete === false"> · {{ $t('provider.sync_incomplete') }}</template>
          </span>
        </dd>
      </dl>
      <p v-if="integration.health?.last_error && !keyRejected" class="text-sm text-warn" data-last-error>
        {{ $t('provider.last_error', { time: dateTime(integration.health.last_error.at) }) }} {{ $te(`errors.${integration.health.last_error.code}`) ? $t(`errors.${integration.health.last_error.code}`) : integration.health.last_error.code }}
      </p>
      <ul class="flex flex-col gap-1 text-[13px] text-text-muted" data-shopware-limits>
        <li>{{ $t('store.api.polling_note') }}</li>
        <li>{{ $t('store.shopware.refunds_note') }}</li>
        <li>{{ $t('store.shopware.state_risk_short') }}</li>
      </ul>

      <div v-if="canManage" class="flex flex-wrap gap-2">
        <button v-if="integration.status === 'active'" type="button" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy" @click="runSync">{{ $t('provider.sync_now') }}</button>
        <button v-if="!confirmingRevoke" type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft" @click="confirmingRevoke = true">{{ $t('store.connector.revoke') }}</button>
      </div>

      <div v-if="confirmingRevoke" class="flex flex-col gap-2 rounded-md border border-warn-border bg-warn-soft p-3 text-sm">
        <p>{{ $t('store.shopware.revoke_warning') }}</p>
        <div class="flex gap-2">
          <button type="button" class="rounded-md bg-crit-solid px-3 py-1.5 font-medium text-white disabled:opacity-60" :disabled="busy" @click="revoke">{{ $t('store.connector.revoke_confirm') }}</button>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" @click="confirmingRevoke = false">{{ $t('common.cancel') }}</button>
        </div>
      </div>
    </template>

    <template v-else>
      <p class="text-sm">{{ $t('store.shopware.why') }}</p>
      <form v-if="canManage" class="flex flex-col gap-4" novalidate @submit.prevent="connect">
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
        <details class="rounded-lg border border-border p-3 text-sm" data-shopware-access>
          <summary class="cursor-pointer font-medium">{{ $t('store.api.access_title') }}</summary>
          <ul class="mt-2 flex list-disc flex-col gap-1 pl-5">
            <li>{{ $t('store.shopware.access_reads') }}</li>
            <li>{{ $t('store.shopware.access_not_read') }}</li>
            <li>{{ $t('store.shopware.access_calls') }}</li>
            <li>{{ $t('store.api.access_revoke') }}</li>
          </ul>
        </details>
        <div class="flex flex-col gap-2 rounded-md border border-warn-border bg-warn-soft p-3 text-sm" data-state-risk>
          <p>{{ $t('store.shopware.state_risk') }}</p>
          <label class="flex items-start gap-2 font-medium">
            <input v-model="form.acknowledged" type="checkbox" class="mt-0.5 size-4 accent-primary" data-acknowledge />
            {{ $t('store.shopware.acknowledge') }}
          </label>
        </div>
        <ul class="flex flex-col gap-1 text-[13px] text-text-muted">
          <li>{{ $t('store.api.verifies_store') }}</li>
          <li>{{ $t('store.shopware.refunds_note') }}</li>
        </ul>
        <div>
          <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !keyValid || !secretValid || !form.acknowledged">{{ $t('store.shopware.connect') }}</button>
        </div>
      </form>
    </template>

    <p v-if="notice" class="text-sm text-ok" role="status">{{ $t(notice) }}</p>
    <ErrorNotice v-if="error" :error="error" />
  </div>
</template>
