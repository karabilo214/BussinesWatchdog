<script setup lang="ts">
import { nextTick, onMounted, ref } from 'vue';
import { Passkeys, UserCancelledError } from '@laravel/passkeys';
import { useI18n } from 'vue-i18n';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import { AppIcon, useAuthFeedback } from '@bw/ui';
import { useSession } from '@/composables/useSession';
import AuthLayout from '@/layouts/AuthLayout.vue';

const route = useRoute();
const router = useRouter();
const { signIn, signInWithPasskey, completeMfa } = useSession();
const { locale } = useI18n();
const connectingPlugin = route.query.redirect === '/connect';
const passkeySupported = ref(false);
const email = ref('');
const password = ref('');
const showPassword = ref(false);
const passkeyButton = ref<HTMLButtonElement | null>(null);
const { form: formRef, summary: errorRef, fields: fieldErrors, validate, focusSummary, focusField } = useAuthFeedback();
const remember = ref(false);
const step = ref<'password' | 'code'>('password');
const code = ref('');
const rememberDevice = ref(false);
const codeInput = ref<HTMLInputElement | null>(null);
const submitting = ref(false);
const EMAIL_LINK_FAILED = route.query.email_changed === '0' || route.query.email_change_cancelled === '0';
const errorKey = ref<string | null>(route.query.expired === '1' ? 'auth.session_expired' : EMAIL_LINK_FAILED ? 'account.email_change.link_invalid' : null);
const infoKey = route.query.reset === '1'
  ? 'account.reset.done'
  : route.query.email_changed === '1'
    ? 'account.email_change.changed'
    : route.query.email_change_cancelled === '1'
      ? 'account.email_change.stopped'
      : null;

onMounted(() => {
  passkeySupported.value = Passkeys.isSupported();
});

async function proceed(): Promise<void> {
  const target = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : { name: 'overview' };
  await router.replace(target);
}

function failure(error: unknown, invalidKey: string): string {
  if (error instanceof ApiError && error.status === 429) {
    return 'auth.rate_limited';
  }

  if (error instanceof ApiError && error.status === 0) {
    return 'common.error_network';
  }

  if (error instanceof ApiError && error.status === 422) {
    return invalidKey;
  }

  return 'common.error_generic';
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
    if ((await signIn(email.value.trim(), password.value, remember.value)) === 'mfa') {
      step.value = 'code';
      await nextTick();
      codeInput.value?.focus();

      return;
    }

    await proceed();
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
    await completeMfa(code.value.trim(), rememberDevice.value);
    await proceed();
  } catch (error) {
    if (error instanceof ApiError && error.code === 'mfa_challenge_expired') {
      await restart('auth.mfa.expired');

      return;
    }

    errorKey.value = failure(error, 'auth.mfa.invalid');
  } finally {
    code.value = '';
    submitting.value = false;
    if (errorKey.value) await focusSummary();
  }
}

async function submitPasskey(): Promise<void> {
  if (submitting.value) return;
  submitting.value = true;
  errorKey.value = null;
  fieldErrors.value = {};

  try {
    await signInWithPasskey(remember.value);
    await proceed();
  } catch (error) {
    if (! (error instanceof UserCancelledError)) {
      errorKey.value = failure(error, 'auth.passkey_failed');
    }
  } finally {
    showPassword.value = false;
    password.value = '';
    submitting.value = false;
    if (errorKey.value) await focusSummary();
    else { await nextTick(); passkeyButton.value?.focus(); }
  }
}

async function restart(reason: string | null = null): Promise<void> {
  step.value = 'password';
  code.value = '';
  rememberDevice.value = false;
  errorKey.value = reason;
  fieldErrors.value = {};
  await nextTick();
  if (reason) await focusSummary(); else focusField('password');
}
</script>

<template>
  <AuthLayout>
    <form ref="formRef" :aria-busy="submitting" aria-labelledby="auth-title" :aria-describedby="errorKey ? 'auth-errors' : undefined" v-if="step === 'password'" class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm" novalidate @submit.prevent="submit">
      <div class="flex flex-col gap-1">
        <h1 id="auth-title" class="text-2xl font-semibold">{{ $t('auth.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('auth.subtitle') }}</p>
      </div>

      <p v-if="connectingPlugin" class="rounded-md border border-note-border bg-note-soft px-3 py-2 text-sm" data-plugin-connect>
        {{ $t('auth.connect_hint') }}
        <a :href="`/${locale}/signup?utm_source=wordpress_plugin&utm_medium=connect`" class="font-medium text-primary hover:underline">{{ $t('auth.connect_signup') }}</a>
      </p>

      <p v-if="infoKey && !errorKey" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok">{{ $t(infoKey) }}</p>

      <div v-if="errorKey" id="auth-errors" :ref="(element) => errorRef = element as HTMLElement | null" role="alert" tabindex="-1" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">
        <p>{{ $t(errorKey) }}</p>
        <ul v-if="Object.keys(fieldErrors).length" class="mt-2 list-disc pl-5">
          <li v-for="(key, name) in fieldErrors" :key="name"><a :href="`#login-${name}`" class="inline-block min-h-6 py-1 underline" @click.prevent="focusField(String(name))">{{ $t(`forms.fields.${name}`) }}: {{ $t(key) }}</a></li>
        </ul>
      </div>

      <div class="flex flex-col gap-1.5">
        <label for="login-email" class="text-sm font-medium">{{ $t('auth.email') }}</label>
        <input
          id="login-email" name="email" :aria-invalid="fieldErrors.email ? true : undefined" :aria-describedby="fieldErrors.email ? 'login-email-error' : undefined"
          v-model="email"
          type="email"
          autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="255"
          required
          class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base"
        />
        <p v-if="fieldErrors.email" id="login-email-error" class="text-sm text-crit">{{ $t(fieldErrors.email) }}</p>
      </div>
      <div class="flex flex-col gap-1.5">
        <label for="login-password" class="text-sm font-medium">{{ $t('auth.password') }}</label>
        <div class="flex items-stretch rounded-md border border-border-strong bg-surface focus-within:outline focus-within:outline-2 focus-within:outline-primary" data-input-group>
          <input
            id="login-password" name="password" :aria-invalid="fieldErrors.password ? true : undefined" :aria-describedby="fieldErrors.password ? 'login-password-error' : undefined"
            v-model="password"
            :type="showPassword ? 'text' : 'password'" maxlength="128"
            autocomplete="current-password"
            required
            class="min-w-0 flex-1 rounded-l-md border-0 bg-transparent px-3 py-2 text-base focus:outline-none"
          />
          <button type="button" class="flex min-h-11 min-w-11 items-center justify-center rounded-r-md text-text-muted hover:text-primary" :aria-label="$t(showPassword ? 'forms.hide_password' : 'forms.show_password')" :title="$t(showPassword ? 'forms.hide_password' : 'forms.show_password')" aria-controls="login-password" :aria-pressed="showPassword" :disabled="submitting" data-toggle-password @click="showPassword = !showPassword">
            <AppIcon :name="showPassword ? 'eye-off' : 'eye'" />
          </button>
        </div>
        <p v-if="fieldErrors.password" id="login-password-error" class="text-sm text-crit">{{ $t(fieldErrors.password) }}</p>
      </div>
      <div class="-mt-2 flex flex-wrap items-center justify-between gap-x-4 gap-y-1 text-sm">
        <RouterLink :to="{ name: 'forgot-password' }" class="text-primary hover:underline">{{ $t('account.forgot.link') }}</RouterLink>
        <a :href="`/${locale}/signup`" class="text-primary hover:underline" data-signup-link>{{ $t('auth.signup_link') }}</a>
      </div>
      <label for="login-remember" class="flex items-center gap-2 text-sm">
        <input id="login-remember" v-model="remember" type="checkbox" class="size-4 accent-primary" />
        {{ $t('auth.remember') }}
      </label>
      <div class="flex items-stretch gap-2">
        <button
          type="submit"
          class="flex-1 rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
          :disabled="submitting"
        >
          {{ submitting ? $t('auth.submitting') : $t('auth.submit') }}
        </button>
        <button ref="passkeyButton" v-if="passkeySupported" type="button" class="flex min-h-11 min-w-11 items-center justify-center rounded-md border border-border-strong px-3 text-primary hover:bg-surface-muted disabled:opacity-60" :aria-label="$t('auth.passkey_sign_in')" :title="$t('auth.passkey_sign_in')" :disabled="submitting" data-passkey @click="submitPasskey">
          <AppIcon name="fingerprint" :size="22" />
        </button>
      </div>
    </form>

    <form ref="formRef" :aria-busy="submitting" aria-labelledby="auth-title" :aria-describedby="errorKey ? 'auth-errors' : undefined" v-else class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm" novalidate data-step="mfa" @submit.prevent="submitCode">
      <div class="flex flex-col gap-1">
        <h1 id="auth-title" class="text-2xl font-semibold">{{ $t('auth.mfa.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('auth.mfa.subtitle') }}</p>
      </div>

      <div v-if="errorKey" id="auth-errors" :ref="(element) => errorRef = element as HTMLElement | null" role="alert" tabindex="-1" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">
        <p>{{ $t(errorKey) }}</p>
        <ul v-if="Object.keys(fieldErrors).length" class="mt-2 list-disc pl-5">
          <li v-for="(key, name) in fieldErrors" :key="name"><a :href="`#login-${name}`" class="inline-block min-h-6 py-1 underline" @click.prevent="focusField(String(name))">{{ $t(`forms.fields.${name}`) }}: {{ $t(key) }}</a></li>
        </ul>
      </div>

      <div class="flex flex-col gap-1.5">
        <label for="login-code" class="text-sm font-medium">{{ $t('auth.mfa.code') }}</label>
        <input
          id="login-code" name="code" :aria-invalid="fieldErrors.code ? true : undefined" :aria-describedby="fieldErrors.code ? 'login-code-error' : 'auth-code-hint'"
          ref="codeInput"
          v-model="code"
          type="text"
          inputmode="text"
          autocomplete="one-time-code" required minlength="6"
          autocapitalize="off"
          spellcheck="false"
          maxlength="32"
          class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base tracking-wider"
        />
        <p v-if="fieldErrors.code" id="login-code-error" class="text-sm text-crit">{{ $t(fieldErrors.code) }}</p>
        <p id="auth-code-hint" class="text-[13px] text-text-muted">{{ $t('auth.mfa.recovery_hint') }}</p>
      </div>
      <label class="flex items-start gap-2 text-sm">
        <input v-model="rememberDevice" type="checkbox" class="mt-1 accent-primary" />
        <span>{{ $t('auth.mfa.remember_device') }}</span>
      </label>
      <button
        type="submit"
        class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
        :disabled="submitting"
      >
        {{ submitting ? $t('auth.submitting') : $t('auth.mfa.submit') }}
      </button>
      <button type="button" class="self-start text-sm text-primary hover:underline" :disabled="submitting" @click="restart()">{{ $t('auth.mfa.back') }}</button>
    </form>
  </AuthLayout>
</template>
