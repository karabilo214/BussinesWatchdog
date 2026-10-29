<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import { useAuthFeedback } from '@bw/ui';
import { LocaleSwitch } from '@bw/i18n';
import { passkeyCancelled, passkeysSupported, signInWithPasskey } from '@/api/passkeys';
import { useStaff } from '@/composables/useStaff';

const route = useRoute();
const router = useRouter();
const { signIn, completeMfa, acceptSession } = useStaff();
const supported = ref(false);
onMounted(() => { supported.value = passkeysSupported(); });
const email = ref('');
const password = ref('');
const showPassword = ref(false);
const passkeyButton = ref<HTMLButtonElement | null>(null);
const { form: formRef, summary: errorRef, fields: fieldErrors, validate, focusSummary, focusField } = useAuthFeedback();
const code = ref('');
const step = ref<'password' | 'code'>('password');
const codeInput = ref<HTMLInputElement | null>(null);
const submitting = ref(false);
const errorKey = ref<string | null>(route.query.expired === '1' ? 'auth.expired' : null);

function failure(error: unknown, invalidKey: string): string {
  if (error instanceof ApiError && error.status === 429) {
    return 'auth.rate_limited';
  }

  if (error instanceof ApiError && error.code === 'platform_mfa_enrollment_required') {
    return 'auth.enrollment_required';
  }

  if (error instanceof ApiError && error.status === 422) {
    return invalidKey;
  }

  return error instanceof ApiError && error.status === 0 ? 'auth.network' : 'auth.generic';
}

async function submitPasskey(): Promise<void> {
  if (submitting.value) return;
  fieldErrors.value = {};
  submitting.value = true;
  errorKey.value = null;
  try {
    acceptSession(await signInWithPasskey());
    const target = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/owner') ? route.query.redirect : { name: 'owner-overview' };
    await router.replace(target);
  } catch (error) {
    if (!passkeyCancelled(error)) errorKey.value = failure(error, 'auth.passkey_failed');
  } finally {
    showPassword.value = false;
    password.value = '';
    submitting.value = false;
    if (errorKey.value) await focusSummary();
    if (!errorKey.value) { await nextTick(); passkeyButton.value?.focus(); }
  }
}

async function submit(): Promise<void> {
  if (submitting.value) return;
  const data = new FormData(formRef.value!);
  email.value = String(data.get('email') ?? email.value);
  password.value = String(data.get('password') ?? password.value);
  if (!validate(['email', 'password'])) { errorKey.value = 'forms.fix_errors'; await focusSummary(); return; }
  submitting.value = true;
  errorKey.value = null;
  fieldErrors.value = {};

  try {
    await signIn(email.value.trim(), password.value);
    step.value = 'code';
    await nextTick();
    codeInput.value?.focus();
  } catch (error) {
    errorKey.value = failure(error, 'auth.failed');
  } finally {
    showPassword.value = false;
    password.value = '';
    submitting.value = false;
    if (errorKey.value) await focusSummary();
  }
}

async function submitCode(): Promise<void> {
  if (submitting.value) return;
  code.value = String(new FormData(formRef.value!).get('code') ?? code.value);
  if (!validate(['code'])) { errorKey.value = 'forms.fix_errors'; await focusSummary(); return; }
  submitting.value = true;
  errorKey.value = null;
  fieldErrors.value = {};

  try {
    await completeMfa(code.value.trim());
    const target = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/owner') ? route.query.redirect : { name: 'owner-overview' };
    await router.replace(target);
  } catch (error) {
    if (error instanceof ApiError && error.code === 'platform_mfa_expired') {
      step.value = 'password';
      errorKey.value = 'auth.mfa_expired';
    } else {
      errorKey.value = failure(error, 'auth.mfa_invalid');
    }
  } finally {
    code.value = '';
    submitting.value = false;
    if (errorKey.value) await focusSummary();
  }
}

async function back(): Promise<void> {
  step.value = 'password';
  code.value = '';
  errorKey.value = null;
  fieldErrors.value = {};
  await nextTick(); focusField('password');
}
</script>

<template>
  <div class="flex min-h-full flex-col px-4 py-8 sm:px-6">
    <div class="flex justify-end">
      <LocaleSwitch />
    </div>
    <main class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-8 py-10">
      <div class="flex flex-col gap-1">
        <span class="flex items-center gap-2 text-lg font-semibold text-brand-800"><img src="/favicon.png" alt="" width="32" height="32" />{{ $t('app.name') }}</span>
        <span class="text-sm text-text-muted">{{ $t('auth.tagline') }}</span>
      </div>

      <form ref="formRef" :aria-busy="submitting" aria-labelledby="auth-title" :aria-describedby="errorKey ? 'auth-errors' : undefined" v-if="step === 'password'" class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm" novalidate @submit.prevent="submit">
        <h1 id="auth-title" class="text-2xl font-semibold">{{ $t('auth.title') }}</h1>
        <div v-if="errorKey" id="auth-errors" :ref="(element) => errorRef = element as HTMLElement | null" role="alert" tabindex="-1" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">
        <p>{{ $t(errorKey) }}</p>
        <ul v-if="Object.keys(fieldErrors).length" class="mt-2 list-disc pl-5">
          <li v-for="(key, name) in fieldErrors" :key="name"><a :href="`#staff-${name}`" class="inline-block min-h-6 py-1 underline" @click.prevent="focusField(String(name))">{{ $t(`forms.fields.${name}`) }}: {{ $t(key) }}</a></li>
        </ul>
      </div>
        <div class="flex flex-col gap-1.5">
          <label for="staff-email" class="text-sm font-medium">{{ $t('auth.email') }}</label>
          <input id="staff-email" name="email" :aria-invalid="fieldErrors.email ? true : undefined" :aria-describedby="fieldErrors.email ? 'staff-email-error' : undefined" v-model="email" type="email" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255" required class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
        <p v-if="fieldErrors.email" id="staff-email-error" class="text-sm text-crit">{{ $t(fieldErrors.email) }}</p>
        </div>
        <div class="flex flex-col gap-1.5">
          <label for="staff-password" class="text-sm font-medium">{{ $t('auth.password') }}</label>
          <input id="staff-password" name="password" :aria-invalid="fieldErrors.password ? true : undefined" :aria-describedby="fieldErrors.password ? 'staff-password-error' : undefined" v-model="password" :type="showPassword ? 'text' : 'password'" maxlength="128" autocomplete="current-password" required class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
        <p v-if="fieldErrors.password" id="staff-password-error" class="text-sm text-crit">{{ $t(fieldErrors.password) }}</p>
        <button type="button" class="min-h-11 self-start text-sm text-primary underline" :aria-controls="'staff-password'" :aria-pressed="showPassword" :disabled="submitting" @click="showPassword = !showPassword">{{ $t(showPassword ? 'forms.hide_password' : 'forms.show_password') }}</button>
        </div>
        <button
          type="submit"
          class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
          :disabled="submitting"
        >
          {{ submitting ? $t('auth.submitting') : $t('auth.continue') }}
        </button>
        <button ref="passkeyButton" v-if="supported" type="button" data-passkey-login class="rounded-md border border-border-strong px-4 py-2.5 text-sm font-semibold text-primary hover:bg-surface-muted disabled:opacity-60" :disabled="submitting" @click="submitPasskey">{{ $t('auth.passkey_sign_in') }}</button>
      </form>

      <form ref="formRef" :aria-busy="submitting" aria-labelledby="auth-title" :aria-describedby="errorKey ? 'auth-errors' : undefined" v-else class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm" novalidate data-step="mfa" @submit.prevent="submitCode">
        <div class="flex flex-col gap-1">
          <h1 id="auth-title" class="text-2xl font-semibold">{{ $t('auth.mfa_title') }}</h1>
          <p class="text-sm text-text-muted">{{ $t('auth.mfa_subtitle') }}</p>
        </div>
        <div v-if="errorKey" id="auth-errors" :ref="(element) => errorRef = element as HTMLElement | null" role="alert" tabindex="-1" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">
        <p>{{ $t(errorKey) }}</p>
        <ul v-if="Object.keys(fieldErrors).length" class="mt-2 list-disc pl-5">
          <li v-for="(key, name) in fieldErrors" :key="name"><a :href="`#staff-${name}`" class="inline-block min-h-6 py-1 underline" @click.prevent="focusField(String(name))">{{ $t(`forms.fields.${name}`) }}: {{ $t(key) }}</a></li>
        </ul>
      </div>
        <div class="flex flex-col gap-1.5">
          <label for="staff-code" class="text-sm font-medium">{{ $t('auth.code') }}</label>
          <input
            id="staff-code" name="code" :aria-invalid="fieldErrors.code ? true : undefined" :aria-describedby="fieldErrors.code ? 'staff-code-error' : 'auth-code-hint'"
            ref="codeInput"
            v-model="code"
            type="text"
            autocomplete="one-time-code" required minlength="6"
            autocapitalize="off"
            spellcheck="false"
            maxlength="32"
            class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base tracking-wider"
          />
        <p v-if="fieldErrors.code" id="staff-code-error" class="text-sm text-crit">{{ $t(fieldErrors.code) }}</p>
          <p id="auth-code-hint" class="text-[13px] text-text-muted">{{ $t('auth.recovery_hint') }}</p>
        </div>
        <button
          type="submit"
          class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
          :disabled="submitting"
        >
          {{ submitting ? $t('auth.submitting') : $t('auth.sign_in') }}
        </button>
        <button type="button" class="self-start text-sm text-primary hover:underline" :disabled="submitting" @click="back">{{ $t('auth.back') }}</button>
      </form>
    </main>
  </div>
</template>
