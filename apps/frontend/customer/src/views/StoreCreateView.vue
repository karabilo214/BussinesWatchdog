<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { createStore, detectStorePlatform } from '@/api/stores';
import type { Locale, StorePlatform } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { browserTimezone, currencies, timezones } from '@/components/storeOptions';
import { fieldErrorsOf } from '@/composables/errors';
import { useRole } from '@/composables/useRole';
import AppLayout from '@/layouts/AppLayout.vue';

const router = useRouter();
const { locale } = useI18n();
const { canManageStores } = useRole();
const logoBase = `${import.meta.env.BASE_URL}onboarding/`;

const form = reactive<{ name: string; base_url: string; timezone: string; default_currency: string; platform: StorePlatform | null }>({ name: '', base_url: 'https://', timezone: browserTimezone(), default_currency: 'EUR', platform: null });
const submitting = ref(false);
const error = ref<unknown>(null);
const zoneOptions = timezones(form.timezone);
const currencyOptions = currencies();
const invalid = computed(() => new Set(fieldErrorsOf(error.value)));
const urlLooksValid = computed(() => /^https:\/\/[^\s/?#]+\.[^\s/?#]+/i.test(form.base_url.trim()));
const detectedPlatform = ref<StorePlatform | null>(null);
const detectionDone = ref(false);
const detecting = ref(false);
const mismatchConfirmed = ref(false);
const mismatch = computed(() => detectedPlatform.value !== null && detectedPlatform.value !== form.platform);

watch(() => form.base_url, () => {
  detectedPlatform.value = null;
  detectionDone.value = false;
  mismatchConfirmed.value = false;
});
watch(() => form.platform, () => { mismatchConfirmed.value = false; });

async function detect(): Promise<void> {
  if (!urlLooksValid.value) return;
  const address = form.base_url.trim();
  detecting.value = true;
  try {
    const found = await detectStorePlatform(address);
    if (form.base_url.trim() === address) {
      detectedPlatform.value = found;
      detectionDone.value = true;
    }
  } catch {
    if (form.base_url.trim() === address) detectionDone.value = true;
  } finally {
    detecting.value = false;
  }
}

async function submit(): Promise<void> {
  if (!form.platform || (mismatch.value && !mismatchConfirmed.value)) return;
  submitting.value = true;
  error.value = null;

  try {
    const store = await createStore({
      name: form.name.trim(),
      base_url: form.base_url.trim(),
      timezone: form.timezone,
      locale: locale.value as Locale,
      default_currency: form.default_currency,
      platform: form.platform,
    });
    await router.push({ name: 'store', params: { id: store.id } });
  } catch (caught) {
    error.value = caught;
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <AppLayout>
    <div class="flex max-w-2xl flex-col gap-6">
      <div class="flex flex-col gap-1">
        <RouterLink :to="{ name: 'overview' }" class="text-sm text-primary hover:underline">← {{ $t('nav.overview') }}</RouterLink>
        <h1 class="text-2xl font-semibold">{{ $t('store.create.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('store.create.subtitle') }}</p>
      </div>

      <p v-if="!canManageStores" class="rounded-md border border-border bg-surface-muted px-3 py-2 text-sm">{{ $t('common.error_forbidden') }}</p>

      <form v-else class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6" novalidate @submit.prevent="submit">
        <ErrorNotice v-if="error" :error="error" />

        <fieldset class="flex flex-col gap-3">
          <legend class="mb-1 text-lg font-semibold">{{ $t('store.form.platform') }}</legend>
          <p class="text-sm text-text-muted">{{ $t('store.create.platform_first') }}</p>
          <div class="grid gap-3 sm:grid-cols-2">
            <label v-for="platform in (['woocommerce', 'shopware'] as const)" :key="platform" class="flex cursor-pointer items-center gap-3 rounded-xl border-2 bg-surface px-4 py-5 hover:bg-surface-muted" :class="form.platform === platform ? 'border-primary' : 'border-border'">
              <input v-model="form.platform" type="radio" name="store-platform" :value="platform" class="size-5 accent-primary" :data-platform="platform" />
              <img :src="`${logoBase}${platform}-logo.svg`" alt="" class="h-9 w-12 object-contain" />
              <span class="text-lg font-semibold">{{ $t(`store.form.platforms.${platform}`) }}</span>
            </label>
          </div>
        </fieldset>

        <template v-if="form.platform">

        <div class="flex flex-col gap-1.5">
          <label for="store-name" class="text-sm font-medium">{{ $t('store.form.name') }}</label>
          <input id="store-name" v-model="form.name" maxlength="100" required :aria-invalid="invalid.has('name')" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
          <p v-if="invalid.has('name')" class="text-[13px] text-crit">{{ $t('store.form.invalid.name') }}</p>
        </div>

        <div class="flex flex-col gap-1.5">
          <label for="store-url" class="text-sm font-medium">{{ $t('store.form.base_url') }}</label>
          <input
            id="store-url"
            v-model="form.base_url"
            type="url"
            inputmode="url"
            autocomplete="url"
            spellcheck="false"
            required
            :aria-invalid="invalid.has('base_url')"
            aria-describedby="store-url-hint"
            class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base"
            @blur="detect"
          />
          <p id="store-url-hint" class="text-[13px] text-text-muted">{{ $t('store.form.base_url_hint') }}</p>
          <p v-if="invalid.has('base_url')" class="text-[13px] text-crit">{{ $t('store.form.invalid.base_url') }}</p>
          <p v-if="detecting" class="text-[13px] text-text-muted" role="status">{{ $t('store.create.detecting') }}</p>
          <p v-else-if="detectedPlatform && !mismatch" class="text-[13px] text-ok">{{ $t('store.create.detected', { platform: $t(`store.form.platforms.${detectedPlatform}`) }) }}</p>
          <div v-else-if="mismatch" class="rounded-md border border-warn-border bg-warn-soft p-3 text-sm" role="alert">
            <p>{{ $t('store.create.mismatch', { selected: $t(`store.form.platforms.${form.platform}`), detected: $t(`store.form.platforms.${detectedPlatform}`) }) }}</p>
            <label class="mt-2 flex items-center gap-2"><input v-model="mismatchConfirmed" type="checkbox" class="size-4 accent-primary" />{{ $t('store.create.mismatch_confirm') }}</label>
          </div>
          <p v-else-if="detectionDone" class="text-[13px] text-text-muted">{{ $t('store.create.not_detected') }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
          <div class="flex flex-col gap-1.5">
            <label for="store-timezone" class="text-sm font-medium">{{ $t('store.form.timezone') }}</label>
            <select id="store-timezone" v-model="form.timezone" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base">
              <option v-for="zone in zoneOptions" :key="zone" :value="zone">{{ zone }}</option>
            </select>
            <p v-if="invalid.has('timezone')" class="text-[13px] text-crit">{{ $t('store.form.invalid.timezone') }}</p>
          </div>
          <div class="flex flex-col gap-1.5">
            <label for="store-currency" class="text-sm font-medium">{{ $t('store.form.currency') }}</label>
            <select id="store-currency" v-model="form.default_currency" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base">
              <option v-for="code in currencyOptions" :key="code" :value="code">{{ code }}</option>
            </select>
          </div>
        </div>

        <div class="flex flex-wrap gap-3">
          <button
            type="submit"
            class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
            :disabled="submitting || detecting || !form.platform || (mismatch && !mismatchConfirmed) || form.name.trim() === '' || !urlLooksValid"
          >
            {{ submitting ? $t('store.create.submitting') : $t('store.create.submit') }}
          </button>
          <RouterLink :to="{ name: 'overview' }" class="rounded-md border border-border-strong bg-surface px-4 py-2.5 text-sm font-medium hover:bg-surface-muted">
            {{ $t('common.cancel') }}
          </RouterLink>
        </div>
        </template>
      </form>
    </div>
  </AppLayout>
</template>
