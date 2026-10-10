<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { createEmailChannel, listChannels } from '@/api/notifications';
import { listStores } from '@/api/stores';
import type { Locale, NotificationChannel, NotificationPreferences, Store } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import ChannelCard from '@/components/notifications/ChannelCard.vue';
import DeliveriesLog from '@/components/notifications/DeliveriesLog.vue';
import PreferencesForm from '@/components/notifications/PreferencesForm.vue';
import { browserTimezone } from '@/components/storeOptions';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';

const { locale } = useI18n();
const { role, canManageStores } = useRole();

const channels = ref<NotificationChannel[]>([]);
const stores = ref<Store[]>([]);
const loading = ref(true);
const error = ref<unknown>(null);
const adding = ref(false);
const form = reactive({ label: '', email: '' });
const creating = ref(false);
const createError = ref<unknown>(null);
const prefs = ref<InstanceType<typeof PreferencesForm> | null>(null);
const refreshKey = ref(0);

const isOwner = computed(() => role.value === 'owner');
const defaults = computed<NotificationPreferences>(() => ({
  min_severity: 'warning',
  locale: locale.value as Locale,
  timezone: browserTimezone(),
  quiet_hours: null,
  critical_bypasses_quiet_hours: false,
  notify_recovery: true,
  store_ids: null,
}));
const formValid = computed(() => form.label.trim() !== '' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()));

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;

  try {
    [channels.value, stores.value] = await Promise.all([listChannels(), listStores()]);
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

function replace(updated: NotificationChannel): void {
  channels.value = channels.value.map((channel) => (channel.id === updated.id ? updated : channel));
  refreshKey.value++;
}

async function create(): Promise<void> {
  if (prefs.value?.valid() === false) return;

  creating.value = true;
  createError.value = null;

  try {
    const channel = await createEmailChannel(form.label.trim(), form.email.trim(), prefs.value?.value() ?? {});
    channels.value = [...channels.value, channel];
    adding.value = false;
    form.label = '';
    form.email = '';
  } catch (caught) {
    createError.value = caught;
  } finally {
    creating.value = false;
  }
}

if (canManageStores.value) {
  void load();
} else {
  listStores()
    .then((value) => {
      stores.value = value;
    })
    .catch(() => undefined)
    .finally(() => {
      loading.value = false;
    });
}
</script>

<template>
  <AppLayout>
    <div class="flex flex-col gap-6">
      <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('notifications.title') }}</h1>
          <p class="max-w-3xl text-sm text-text-muted">{{ $t('notifications.subtitle') }}</p>
        </div>
        <button
          v-if="canManageStores && !adding"
          type="button"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover"
          @click="adding = true"
        >
          {{ $t('notifications.add') }}
        </button>
      </div>

      <p v-if="!canManageStores" class="rounded-md border border-border bg-surface-muted px-3 py-2 text-sm">{{ $t('notifications.read_only') }}</p>

      <form v-if="adding" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" novalidate @submit.prevent="create" data-panel="new-channel">
        <h2 class="text-lg font-semibold">{{ $t('notifications.new.title') }}</h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <label class="flex flex-col gap-1.5 text-sm font-medium">
            {{ $t('notifications.new.label') }}
            <input v-model="form.label" maxlength="100" :placeholder="$t('notifications.new.label_placeholder')" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal" />
          </label>
          <label class="flex flex-col gap-1.5 text-sm font-medium">
            {{ $t('notifications.new.email') }}
            <input v-model="form.email" type="email" autocomplete="email" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal" />
          </label>
        </div>
        <PreferencesForm ref="prefs" id-prefix="new" :preferences="defaults" :stores="stores" :is-owner="isOwner" />
        <p class="text-[13px] text-text-muted">{{ $t('notifications.new.note') }}</p>
        <ErrorNotice v-if="createError" :error="createError" />
        <div class="flex flex-wrap gap-2">
          <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="creating || !formValid">
            {{ $t('notifications.new.submit') }}
          </button>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium" @click="adding = false">{{ $t('common.cancel') }}</button>
        </div>
      </form>

      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />

      <template v-else-if="canManageStores">
        <p v-if="channels.length === 0 && !adding" class="rounded-xl border border-dashed border-border-strong bg-surface p-6 text-sm text-text-muted" data-empty>{{ $t('notifications.empty') }}</p>
        <ul v-else class="flex flex-col gap-4">
          <ChannelCard v-for="channel in channels" :key="channel.id" :channel="channel" :stores="stores" :is-owner="isOwner" @changed="replace" @tested="refreshKey++" />
        </ul>
      </template>

      <DeliveriesLog v-if="!loading" :channels="channels" :refresh-key="refreshKey" />
    </div>
  </AppLayout>
</template>
