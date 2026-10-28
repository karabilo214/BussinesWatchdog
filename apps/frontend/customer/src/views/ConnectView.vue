<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import { approvePluginConnect, cancelPluginConnect, getPluginConnect, returnToWordPress, type PluginConnectRequest, type PluginConnectStore } from '@/api/plugin';
import { createStore } from '@/api/stores';
import type { Locale } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { browserTimezone, currencies, timezones } from '@/components/storeOptions';
import { useFormat } from '@/composables/useFormat';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';

const router = useRouter();
const { locale } = useI18n();
const { dateTime } = useFormat();
const { canManageIntegrations, canManageStores } = useRole();

const connect = ref<PluginConnectRequest | null>(null);
const state = ref<'loading' | 'ready' | 'missing' | 'forbidden' | 'failed'>('loading');
const selected = ref<string | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const form = reactive({ name: '', timezone: browserTimezone(), default_currency: 'EUR' });
const zoneOptions = timezones(form.timezone);
const currencyOptions = currencies();

const usable = computed(() => connect.value?.stores.filter((store) => blocker(store) === null) ?? []);

function blocker(store: PluginConnectStore): string | null {
  if (store.platform !== 'woocommerce') return 'plugin_connect.blocked_platform';
  if (store.order_source === 'woocommerce_api' || store.order_source === 'shopware') return 'plugin_connect.blocked_api';

  return null;
}

onMounted(async () => {
  try {
    connect.value = await getPluginConnect();
    form.name = connect.value.site_host;
    selected.value = usable.value[0]?.id ?? null;
    state.value = 'ready';
  } catch (caught) {
    state.value = caught instanceof ApiError && caught.status === 404 ? 'missing' : caught instanceof ApiError && caught.status === 403 ? 'forbidden' : 'failed';
    error.value = state.value === 'failed' ? caught : null;
  }
});

async function approve(storeId: string): Promise<void> {
  returnToWordPress(await approvePluginConnect(storeId));
}

async function submit(): Promise<void> {
  if (connect.value === null) return;
  busy.value = true;
  error.value = null;

  try {
    if (connect.value.stores.length === 0) {
      const store = await createStore({ name: form.name.trim(), base_url: connect.value.site, timezone: form.timezone, locale: locale.value as Locale, default_currency: form.default_currency, platform: 'woocommerce' });
      await approve(store.id);
    } else if (selected.value !== null) {
      await approve(selected.value);
    }
  } catch (caught) {
    error.value = caught;
    busy.value = false;
  }
}

async function cancel(): Promise<void> {
  await cancelPluginConnect().catch(() => undefined);
  await router.push({ name: 'overview' });
}
</script>

<template>
  <AppLayout>
    <div class="flex max-w-2xl flex-col gap-6" data-plugin-connect>
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('plugin_connect.title') }}</h1>
        <p v-if="connect" class="text-sm text-text-muted">{{ $t('plugin_connect.subtitle', { site: connect.site_host, organization: connect.organization }) }}</p>
      </div>

      <p v-if="state === 'loading'" role="status" class="text-sm">{{ $t('common.loading') }}</p>

      <div v-else-if="state === 'missing'" class="flex flex-col gap-3 rounded-xl border border-border bg-surface p-6" data-state="missing">
        <p>{{ $t('plugin_connect.missing') }}</p>
        <RouterLink :to="{ name: 'overview' }" class="self-start text-sm text-primary hover:underline">{{ $t('nav.overview') }}</RouterLink>
      </div>

      <p v-else-if="state === 'forbidden'" class="rounded-md border border-border bg-surface-muted px-3 py-2 text-sm" data-state="forbidden">{{ $t('plugin_connect.forbidden') }}</p>

      <ErrorNotice v-else-if="state === 'failed'" :error="error" />

      <form v-else-if="connect" class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6" novalidate @submit.prevent="submit">
        <ErrorNotice v-if="error" :error="error" />

        <template v-if="connect.stores.length > 0">
          <fieldset class="flex flex-col gap-3">
            <legend class="mb-1 text-lg font-semibold">{{ $t('plugin_connect.choose_store') }}</legend>
            <label v-for="store in connect.stores" :key="store.id" class="flex items-start gap-3 rounded-lg border px-4 py-3" :class="[selected === store.id ? 'border-primary' : 'border-border', blocker(store) ? 'opacity-70' : 'cursor-pointer hover:bg-surface-muted']" :data-store="store.id">
              <input v-model="selected" type="radio" name="plugin-connect-store" :value="store.id" :disabled="blocker(store) !== null || !canManageIntegrations" class="mt-1 size-4 accent-primary" />
              <span class="flex flex-col gap-0.5">
                <span class="font-medium">{{ store.name }}</span>
                <span class="text-[13px] text-text-muted">{{ store.base_url }}</span>
                <span v-if="blocker(store)" class="text-[13px] text-warn">{{ $t(blocker(store)!) }}</span>
                <span v-else-if="store.order_source === 'woocommerce'" class="text-[13px] text-text-muted">{{ $t('plugin_connect.reconnect') }}</span>
              </span>
            </label>
          </fieldset>
          <p v-if="usable.length === 0" class="text-sm">{{ $t('plugin_connect.no_usable') }}</p>
        </template>

        <template v-else>
          <div class="flex flex-col gap-1">
            <h2 class="text-lg font-semibold">{{ $t('plugin_connect.create_title') }}</h2>
            <p class="text-sm text-text-muted">{{ $t('plugin_connect.create_hint', { site: connect.site }) }}</p>
          </div>
          <p v-if="!canManageStores" class="text-sm">{{ $t('common.error_forbidden') }}</p>
          <div class="flex flex-col gap-1.5">
            <label for="connect-store-name" class="text-sm font-medium">{{ $t('store.form.name') }}</label>
            <input id="connect-store-name" v-model="form.name" maxlength="100" required class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="flex flex-col gap-1.5">
              <label for="connect-store-timezone" class="text-sm font-medium">{{ $t('store.form.timezone') }}</label>
              <select id="connect-store-timezone" v-model="form.timezone" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base">
                <option v-for="zone in zoneOptions" :key="zone" :value="zone">{{ zone }}</option>
              </select>
            </div>
            <div class="flex flex-col gap-1.5">
              <label for="connect-store-currency" class="text-sm font-medium">{{ $t('store.form.currency') }}</label>
              <select id="connect-store-currency" v-model="form.default_currency" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base">
                <option v-for="code in currencyOptions" :key="code" :value="code">{{ code }}</option>
              </select>
            </div>
          </div>
        </template>

        <p class="text-[13px] text-text-muted">{{ $t('plugin_connect.what_happens') }}</p>

        <div class="flex flex-wrap gap-3">
          <button
            type="submit"
            class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
            :disabled="busy || !canManageIntegrations || (connect.stores.length > 0 ? selected === null : !canManageStores || form.name.trim() === '')"
            data-action="connect"
          >
            {{ busy ? $t('plugin_connect.connecting') : connect.stores.length > 0 ? $t('plugin_connect.connect') : $t('plugin_connect.create_and_connect') }}
          </button>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2.5 text-sm font-medium hover:bg-surface-muted" data-action="cancel" @click="cancel">{{ $t('common.cancel') }}</button>
        </div>
        <p class="text-[13px] text-text-muted">{{ $t('plugin_connect.expires', { time: dateTime(connect.expires_at) }) }} {{ $t('plugin_connect.manual') }}</p>
      </form>
    </div>
  </AppLayout>
</template>
