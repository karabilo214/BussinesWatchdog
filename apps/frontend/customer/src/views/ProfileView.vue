<script setup lang="ts">
import { computed, inject, reactive, ref, watch } from 'vue';
import { LOCALE_NAMES, LOCALES, SET_LOCALE } from '@bw/i18n';
import { changePassword, resendEmailVerification, updateProfile } from '@/api/account';
import type { Locale } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { fieldErrorsOf } from '@/composables/errors';
import PasswordFields from '@/components/account/PasswordFields.vue';
import SettingsTabs from '@/components/account/SettingsTabs.vue';
import { useSession } from '@/composables/useSession';
import AppLayout from '@/layouts/AppLayout.vue';
import { StatusBadge } from '@bw/ui';

const { session, replace } = useSession();
const setLocale = inject(SET_LOCALE, null);

const profile = reactive({ name: '', locale: 'ru' as Locale });
const profileSaving = ref(false);
const profileSaved = ref(false);
const profileError = ref<unknown>(null);
const resendState = ref<'idle' | 'sent'>('idle');
const resendError = ref<unknown>(null);
const currentPassword = ref('');
const newPassword = ref('');
const newPasswordValid = ref(false);
const passwordSaving = ref(false);
const passwordSaved = ref(false);
const passwordError = ref<unknown>(null);
const passwordForm = ref(0);
const currentInvalid = computed(() => fieldErrorsOf(passwordError.value).includes('current_password'));

watch(
  () => session.value?.user,
  (user) => {
    if (user) {
      profile.name = user.name;
      profile.locale = user.locale;
    }
  },
  { immediate: true },
);

async function saveProfile(): Promise<void> {
  profileSaving.value = true;
  profileError.value = null;
  profileSaved.value = false;

  try {
    replace(await updateProfile({ name: profile.name.trim(), locale: profile.locale }));
    setLocale?.(profile.locale);
    profileSaved.value = true;
  } catch (caught) {
    profileError.value = caught;
  } finally {
    profileSaving.value = false;
  }
}

async function resend(): Promise<void> {
  resendError.value = null;

  try {
    await resendEmailVerification();
    resendState.value = 'sent';
  } catch (caught) {
    resendError.value = caught;
  }
}

async function savePassword(): Promise<void> {
  passwordSaving.value = true;
  passwordError.value = null;
  passwordSaved.value = false;

  try {
    await changePassword(currentPassword.value, newPassword.value);
    passwordSaved.value = true;
    currentPassword.value = '';
    passwordForm.value++;
  } catch (caught) {
    passwordError.value = caught;
  } finally {
    passwordSaving.value = false;
  }
}
</script>

<template>
  <AppLayout>
    <div class="flex max-w-3xl flex-col gap-6">
      <h1 class="text-2xl font-semibold">{{ $t('settings.title') }}</h1>
      <SettingsTabs />

      <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="profile-title" data-panel="profile">
        <h2 id="profile-title" class="text-lg font-semibold">{{ $t('account.profile.title') }}</h2>
        <div class="flex flex-wrap items-center gap-2 text-sm">
          <span class="font-mono">{{ session?.user.email }}</span>
          <StatusBadge v-if="session?.user.email_verified" tone="ok" :label="$t('account.profile.email_verified')" />
          <StatusBadge v-else tone="warn" :label="$t('account.profile.email_unverified')" />
          <button v-if="!session?.user.email_verified && resendState === 'idle'" type="button" class="text-primary hover:underline" @click="resend">{{ $t('account.verify.resend') }}</button>
          <span v-if="resendState === 'sent'" class="text-ok" role="status">{{ $t('account.verify.sent') }}</span>
        </div>
        <ErrorNotice v-if="resendError" :error="resendError" />
        <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="saveProfile">
          <div class="flex flex-col gap-1.5">
            <label for="profile-name" class="text-sm font-medium">{{ $t('account.profile.name') }}</label>
            <input id="profile-name" v-model="profile.name" maxlength="100" autocomplete="name" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
          </div>
          <div class="flex flex-col gap-1.5">
            <label for="profile-locale" class="text-sm font-medium">{{ $t('account.profile.locale') }}</label>
            <select id="profile-locale" v-model="profile.locale" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base">
              <option v-for="code in LOCALES" :key="code" :value="code">{{ LOCALE_NAMES[code] }}</option>
            </select>
          </div>
          <div class="flex flex-wrap items-center gap-3 sm:col-span-2">
            <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="profileSaving || profile.name.trim() === ''">{{ $t('common.save') }}</button>
            <span v-if="profileSaved" class="text-sm text-ok" role="status">{{ $t('common.saved') }}</span>
          </div>
        </form>
        <ErrorNotice v-if="profileError" :error="profileError" />
      </section>

      <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="password-title" data-panel="password">
        <h2 id="password-title" class="text-lg font-semibold">{{ $t('account.password.title') }}</h2>
        <form :key="passwordForm" class="flex flex-col gap-4 sm:max-w-md" @submit.prevent="savePassword">
          <div class="flex flex-col gap-1.5">
            <label for="password-current" class="text-sm font-medium">{{ $t('account.password.current') }}</label>
            <input id="password-current" v-model="currentPassword" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
          </div>
          <PasswordFields id-prefix="change" @update="(value, ok) => { newPassword = value; newPasswordValid = ok; }" />
          <p class="text-[13px] text-text-muted">{{ $t('account.password.other_sessions') }}</p>
          <p v-if="currentInvalid" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">{{ $t('account.password.current_invalid') }}</p>
          <ErrorNotice v-else-if="passwordError" :error="passwordError" />
          <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="passwordSaving || currentPassword === '' || !newPasswordValid">
              {{ $t('account.password.change') }}
            </button>
          </div>
        </form>
        <p v-if="passwordSaved" class="text-sm text-ok" role="status">{{ $t('account.password.changed') }}</p>
      </section>
    </div>
  </AppLayout>
</template>
