<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { ApiError } from '@bw/api-client';
import { updateStore } from '@/api/stores';
import type { Store, UpdateStoreInput } from '@/api/types';
import { fieldErrorsOf } from '@/composables/errors';
import ErrorNotice from './ErrorNotice.vue';
import { currencies, timezones } from './storeOptions';

const props = defineProps<{ store: Store; canManage: boolean }>();
const emit = defineEmits<{ updated: [store: Store]; stale: [] }>();

const form = reactive({ name: '', timezone: '', default_currency: '' });
const saving = ref(false);
const error = ref<unknown>(null);
const saved = ref(false);
const invalid = computed(() => new Set(fieldErrorsOf(error.value)));
const zoneOptions = computed(() => timezones(props.store.timezone));
const currencyOptions = computed(() => currencies(props.store.default_currency));
const dirty = computed(
  () => form.name.trim() !== props.store.name || form.timezone !== props.store.timezone || form.default_currency !== props.store.default_currency,
);
const canPause = computed(() => props.store.status === 'active' || props.store.status === 'degraded');
const canResume = computed(() => props.store.status === 'paused');

watch(
  () => props.store,
  (store) => {
    form.name = store.name;
    form.timezone = store.timezone;
    form.default_currency = store.default_currency;
  },
  { immediate: true },
);

async function apply(changes: UpdateStoreInput): Promise<void> {
  saving.value = true;
  error.value = null;
  saved.value = false;

  try {
    emit('updated', await updateStore(props.store, changes));
    saved.value = true;
  } catch (caught) {
    error.value = caught;

    if (caught instanceof ApiError && caught.status === 409) {
      emit('stale');
    }
  } finally {
    saving.value = false;
  }
}

function save(): Promise<void> {
  const changes: UpdateStoreInput = {};

  if (form.name.trim() !== props.store.name) changes.name = form.name.trim();
  if (form.timezone !== props.store.timezone) changes.timezone = form.timezone;
  if (form.default_currency !== props.store.default_currency) changes.default_currency = form.default_currency;

  return apply(changes);
}
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="settings-title" data-panel="settings">
    <div class="flex flex-col gap-1">
      <h2 id="settings-title" class="text-lg font-semibold">{{ $t('store.settings.title') }}</h2>
      <p class="text-sm text-text-muted">{{ $t('store.settings.base_url', { url: store.base_url }) }}</p>
    </div>

    <form class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
      <div class="flex flex-col gap-1.5 sm:col-span-2">
        <label for="settings-name" class="text-sm font-medium">{{ $t('store.form.name') }}</label>
        <input
          id="settings-name"
          v-model="form.name"
          maxlength="100"
          :disabled="!canManage"
          :aria-invalid="invalid.has('name')"
          class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base disabled:bg-surface-muted"
        />
        <p v-if="invalid.has('name')" class="text-[13px] text-crit">{{ $t('store.form.invalid.name') }}</p>
      </div>
      <div class="flex flex-col gap-1.5">
        <label for="settings-timezone" class="text-sm font-medium">{{ $t('store.form.timezone') }}</label>
        <select id="settings-timezone" v-model="form.timezone" :disabled="!canManage" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base disabled:bg-surface-muted">
          <option v-for="zone in zoneOptions" :key="zone" :value="zone">{{ zone }}</option>
        </select>
      </div>
      <div class="flex flex-col gap-1.5">
        <label for="settings-currency" class="text-sm font-medium">{{ $t('store.form.currency') }}</label>
        <select id="settings-currency" v-model="form.default_currency" :disabled="!canManage" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base disabled:bg-surface-muted">
          <option v-for="code in currencyOptions" :key="code" :value="code">{{ code }}</option>
        </select>
        <p class="text-[13px] text-text-muted">{{ $t('store.settings.currency_note') }}</p>
      </div>
      <div v-if="canManage" class="flex flex-wrap items-center gap-3 sm:col-span-2">
        <button
          type="submit"
          class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
          :disabled="saving || !dirty || form.name.trim() === ''"
        >
          {{ $t('common.save') }}
        </button>
        <span v-if="saved" class="text-sm text-ok" role="status">{{ $t('common.saved') }}</span>
      </div>
    </form>

    <ErrorNotice v-if="error" :error="error" />

    <div v-if="canManage && (canPause || canResume)" class="flex flex-col gap-2 border-t border-border pt-4">
      <p class="text-sm text-text-muted">{{ canPause ? $t('store.settings.pause_hint') : $t('store.settings.resume_hint') }}</p>
      <div>
        <button
          v-if="canPause"
          type="button"
          class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium hover:bg-surface-muted disabled:opacity-60"
          :disabled="saving"
          @click="apply({ status: 'paused' })"
        >
          {{ $t('store.settings.pause') }}
        </button>
        <button
          v-else
          type="button"
          class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
          :disabled="saving"
          @click="apply({ status: 'active' })"
        >
          {{ $t('store.settings.resume') }}
        </button>
      </div>
    </div>
  </section>
</template>
