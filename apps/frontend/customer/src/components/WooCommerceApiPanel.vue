<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { connectWooCommerceApi, syncProvider } from '@/api/providers';
import { detectStorePlatform, listStoreIntegrations, revokeIntegration } from '@/api/stores';
import type { Integration } from '@/api/types';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import ErrorNotice from './ErrorNotice.vue';
import ConnectionProgress from './ConnectionProgress.vue';
import { INTEGRATION_TONES } from './integrationTones';
import WooCommerceWebhookStatus from './WooCommerceWebhookStatus.vue';

const props = defineProps<{ storeId: string; baseUrl?: string; canManage: boolean; pluginActive: boolean }>();
const emit = defineEmits<{ changed: [] }>();
const { dateTime } = useFormat();

const integration = ref<Integration | null>(null);
const loading = ref(true);
const loadError = ref<unknown>(null);
const form = reactive({ key: '', secret: '' });
const busy = ref(false);
const error = ref<unknown>(null);
const platformMismatch = ref(false);
const notice = ref<string | null>(null);
const confirmingRevoke = ref(false);

const keyValid = computed(() => /^ck_[a-f0-9]{40}$/.test(form.key.trim()));
const secretValid = computed(() => /^cs_[a-f0-9]{40}$/.test(form.secret.trim()));
const keyRejected = computed(() => integration.value?.health?.key_rejected_at !== undefined);
const sync = computed(() => integration.value?.health?.sync ?? null);
const webhook = computed(() => integration.value?.health?.webhook ?? null);
const screenshotUrl = `${import.meta.env.BASE_URL}onboarding/woo-api-key-form.png`;

async function load(): Promise<void> {
  try {
    const all = await listStoreIntegrations(props.storeId);
    integration.value = all.find((item) => item.provider === 'woocommerce_api' && ['active', 'degraded', 'pending'].includes(item.status)) ?? null;
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
  platformMismatch.value = false;
  notice.value = null;

  try {
    await action();
    notice.value = success;
  } catch (caught) {
    error.value = caught;
    if (!integration.value && props.baseUrl) {
      try { platformMismatch.value = (await detectStorePlatform(props.baseUrl)) === 'shopware'; } catch { /* Keep the original connection error. */ }
    }
    await load();
  } finally {
    busy.value = false;
  }
}

function connect(): Promise<void> {
  return act(async () => {
    integration.value = await connectWooCommerceApi(props.storeId, { consumer_key: form.key.trim(), consumer_secret: form.secret.trim() });
    form.key = '';
    form.secret = '';
    emit('changed');
  }, 'store.api.connected');
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
  }, 'store.api.revoked');
}

void load();
</script>

<template>
  <div class="flex flex-col gap-4" data-connector="api">
    <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
    <ErrorNotice v-else-if="loadError" :error="loadError" />

    <template v-else-if="integration">
      <p v-if="keyRejected" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit" data-key-rejected>{{ $t('store.api.key_rejected') }}</p>

      <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[13rem_1fr]">
        <dt class="text-text-muted">{{ $t('store.api.state') }}</dt>
        <dd><StatusBadge :tone="INTEGRATION_TONES[integration.status]" :label="$t(`store.connector.status.${integration.status}`)" /></dd>
        <dt class="text-text-muted">{{ $t('store.api.key') }}</dt>
        <dd class="font-mono text-[13px]">ck_••••{{ integration.health?.key_last7 ?? '' }} <span class="font-sans text-text-muted">· {{ $t('store.api.read_only') }}</span></dd>
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
      <WooCommerceWebhookStatus :integration="integration" :can-manage="canManage" @reload="load" />

      <ul class="flex flex-col gap-1 text-[13px] text-text-muted" data-api-limits>
        <li>{{ webhook?.last_received_at ? $t('store.api.polling_note_with_webhook') : $t('store.api.polling_note') }}</li>
        <li>{{ $t('store.api.late_refunds_warning') }}</li>
        <li>{{ $t('store.api.mode_warning') }}</li>
      </ul>

      <div v-if="canManage" class="flex flex-wrap gap-2">
        <button v-if="integration.status === 'active'" type="button" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy" @click="runSync">{{ $t('provider.sync_now') }}</button>
        <button v-if="!confirmingRevoke" type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft" @click="confirmingRevoke = true">{{ $t('store.connector.revoke') }}</button>
      </div>

      <div v-if="confirmingRevoke" class="flex flex-col gap-2 rounded-md border border-warn-border bg-warn-soft p-3 text-sm">
        <p>{{ $t('store.api.revoke_warning') }}</p>
        <div class="flex gap-2">
          <button type="button" class="rounded-md bg-crit-solid px-3 py-1.5 font-medium text-white disabled:opacity-60" :disabled="busy" @click="revoke">{{ $t('store.connector.revoke_confirm') }}</button>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" @click="confirmingRevoke = false">{{ $t('common.cancel') }}</button>
        </div>
      </div>
    </template>

    <p v-else-if="pluginActive" class="text-sm" data-other-source>{{ $t('store.api.plugin_active', { block: $t('store.order_source.extra_woocommerce') }) }}</p>

    <template v-else>
      <p class="text-sm">{{ $t('store.api.why') }}</p>
      <form v-if="canManage" class="flex flex-col gap-4" novalidate @submit.prevent="connect">
        <ol class="list-decimal space-y-1 pl-5 text-sm">
          <li>{{ $t('store.api.step_open') }}</li>
          <li>{{ $t('store.api.step_permissions') }}</li>
          <li>{{ $t('store.api.step_paste') }}</li>
        </ol>
        <figure class="max-w-3xl overflow-hidden rounded-lg border border-border bg-surface-muted">
          <img :src="screenshotUrl" :alt="$t('store.api.screenshot_alt')" loading="lazy" class="w-full" />
          <figcaption class="p-3 text-[13px] text-text-muted">{{ $t('store.api.screenshot_caption') }}</figcaption>
        </figure>
        <p class="text-[13px] text-text-muted">{{ $t('store.api.requirements') }}</p>
        <div class="grid gap-3 sm:grid-cols-2">
          <div class="flex flex-col gap-1.5">
            <label for="wc-consumer-key" class="text-sm font-medium">{{ $t('store.api.key_label') }}</label>
            <input id="wc-consumer-key" v-model="form.key" autocomplete="off" spellcheck="false" placeholder="ck_…" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm" />
            <p v-if="form.key && !keyValid" class="text-[13px] text-crit">{{ $t('store.api.key_hint') }}</p>
          </div>
          <div class="flex flex-col gap-1.5">
            <label for="wc-consumer-secret" class="text-sm font-medium">{{ $t('store.api.secret_label') }}</label>
            <input id="wc-consumer-secret" v-model="form.secret" type="password" autocomplete="off" spellcheck="false" placeholder="cs_…" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-sm" />
            <p v-if="form.secret && !secretValid" class="text-[13px] text-crit">{{ $t('store.api.secret_hint') }}</p>
          </div>
        </div>
        <p class="text-[13px] text-text-muted">{{ $t('store.order_source.key_security') }}</p>
        <details class="rounded-lg border border-border p-3 text-sm" data-api-access>
          <summary class="cursor-pointer font-medium">{{ $t('store.api.access_title') }}</summary>
          <ul class="mt-2 flex list-disc flex-col gap-1 pl-5">
            <li>{{ $t('store.api.access_reads') }}</li>
            <li>{{ $t('store.api.access_not_read') }}</li>
            <li>{{ $t('store.api.access_scope') }}</li>
            <li>{{ $t('store.api.access_no_write') }}</li>
            <li>{{ $t('store.api.access_frequency') }}</li>
            <li>{{ $t('store.api.access_revoke') }}</li>
          </ul>
        </details>
        <ul class="flex flex-col gap-1 text-[13px] text-text-muted" data-api-limits>
          <li>{{ $t('store.api.verifies_store') }}</li>
          <li>{{ $t('store.api.late_refunds_warning') }}</li>
          <li>{{ $t('store.api.mode_warning') }}</li>
        </ul>
        <div>
          <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !keyValid || !secretValid">{{ $t('store.api.connect') }}</button>
        </div>
      </form>
    </template>

    <p v-if="notice" class="text-sm text-ok" role="status">{{ $t(notice) }}</p>
    <ErrorNotice v-if="error" :error="error"><ConnectionProgress :error="error" platform="woocommerce" /></ErrorNotice>
    <p v-if="platformMismatch" role="alert" class="rounded-md border border-warn-border bg-warn-soft px-3 py-2 text-sm text-warn">{{ $t('store.order_source.platform_mismatch', { platform: 'Shopware' }) }} <a href="#store-settings" class="font-semibold text-primary hover:underline">{{ $t('store.order_source.change_platform') }}</a></p>
  </div>
</template>
