<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import { useSession } from '@/composables/useSession';
import AuthLayout from '@/layouts/AuthLayout.vue';

const route = useRoute();
const router = useRouter();
const { signIn, completeMfa } = useSession();
const email = ref('');
const password = ref('');
const remember = ref(false);
const step = ref<'password' | 'code'>('password');
const code = ref('');
const codeInput = ref<HTMLInputElement | null>(null);
const submitting = ref(false);
const errorKey = ref<string | null>(route.query.expired === '1' ? 'auth.session_expired' : null);
const infoKey = route.query.reset === '1' ? 'account.reset.done' : null;

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
  submitting.value = true;
  errorKey.value = null;

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
    password.value = '';
    submitting.value = false;
  }
}

async function submitCode(): Promise<void> {
  submitting.value = true;
  errorKey.value = null;

  try {
    await completeMfa(code.value.trim());
    await proceed();
  } catch (error) {
    if (error instanceof ApiError && error.code === 'mfa_challenge_expired') {
      restart('auth.mfa.expired');

      return;
    }

    errorKey.value = failure(error, 'auth.mfa.invalid');
  } finally {
    code.value = '';
    submitting.value = false;
  }
}

function restart(reason: string | null = null): void {
  step.value = 'password';
  code.value = '';
  errorKey.value = reason;
}
</script>

<template>
  <AuthLayout>
    <form v-if="step === 'password'" class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm" novalidate @submit.prevent="submit">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('auth.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('auth.subtitle') }}</p>
      </div>

      <p v-if="infoKey && !errorKey" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok">{{ $t(infoKey) }}</p>

      <p v-if="errorKey" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">
        {{ $t(errorKey) }}
      </p>

      <div class="flex flex-col gap-1.5">
        <label for="login-email" class="text-sm font-medium">{{ $t('auth.email') }}</label>
        <input
          id="login-email"
          v-model="email"
          type="email"
          autocomplete="username"
          required
          class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base"
        />
      </div>
      <div class="flex flex-col gap-1.5">
        <label for="login-password" class="text-sm font-medium">{{ $t('auth.password') }}</label>
        <input
          id="login-password"
          v-model="password"
          type="password"
          autocomplete="current-password"
          required
          class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base"
        />
      </div>
      <RouterLink :to="{ name: 'forgot-password' }" class="-mt-2 self-start text-sm text-primary hover:underline">{{ $t('account.forgot.link') }}</RouterLink>
      <label for="login-remember" class="flex items-center gap-2 text-sm">
        <input id="login-remember" v-model="remember" type="checkbox" class="size-4 accent-primary" />
        {{ $t('auth.remember') }}
      </label>
      <button
        type="submit"
        class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
        :disabled="submitting || email === '' || password === ''"
      >
        {{ submitting ? $t('auth.submitting') : $t('auth.submit') }}
      </button>
    </form>

    <form v-else class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm" novalidate data-step="mfa" @submit.prevent="submitCode">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('auth.mfa.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('auth.mfa.subtitle') }}</p>
      </div>

      <p v-if="errorKey" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">
        {{ $t(errorKey) }}
      </p>

      <div class="flex flex-col gap-1.5">
        <label for="login-code" class="text-sm font-medium">{{ $t('auth.mfa.code') }}</label>
        <input
          id="login-code"
          ref="codeInput"
          v-model="code"
          type="text"
          inputmode="text"
          autocomplete="one-time-code"
          autocapitalize="off"
          spellcheck="false"
          maxlength="32"
          class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base tracking-wider"
        />
        <p class="text-[13px] text-text-muted">{{ $t('auth.mfa.recovery_hint') }}</p>
      </div>
      <button
        type="submit"
        class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
        :disabled="submitting || code.trim().length < 6"
      >
        {{ submitting ? $t('auth.submitting') : $t('auth.mfa.submit') }}
      </button>
      <button type="button" class="self-start text-sm text-primary hover:underline" @click="restart()">{{ $t('auth.mfa.back') }}</button>
    </form>
  </AuthLayout>
</template>
