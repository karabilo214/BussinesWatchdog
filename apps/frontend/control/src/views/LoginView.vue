<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import { LocaleSwitch } from '@bw/i18n';
import { useStaff } from '@/composables/useStaff';

const route = useRoute();
const router = useRouter();
const { signIn, completeMfa } = useStaff();
const email = ref('');
const password = ref('');
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

async function submit(): Promise<void> {
  submitting.value = true;
  errorKey.value = null;

  try {
    await signIn(email.value.trim(), password.value);
    step.value = 'code';
    await nextTick();
    codeInput.value?.focus();
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
  }
}

function back(): void {
  step.value = 'password';
  code.value = '';
  errorKey.value = null;
}
</script>

<template>
  <div class="flex min-h-full flex-col px-4 py-8 sm:px-6">
    <div class="flex justify-end">
      <LocaleSwitch />
    </div>
    <main class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-8 py-10">
      <div class="flex flex-col gap-1">
        <span class="text-lg font-semibold text-brand-800">{{ $t('app.name') }}</span>
        <span class="text-sm text-text-muted">{{ $t('auth.tagline') }}</span>
      </div>

      <form v-if="step === 'password'" class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm" novalidate @submit.prevent="submit">
        <h1 class="text-2xl font-semibold">{{ $t('auth.title') }}</h1>
        <p v-if="errorKey" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">{{ $t(errorKey) }}</p>
        <div class="flex flex-col gap-1.5">
          <label for="staff-email" class="text-sm font-medium">{{ $t('auth.email') }}</label>
          <input id="staff-email" v-model="email" type="email" autocomplete="username" required class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
        </div>
        <div class="flex flex-col gap-1.5">
          <label for="staff-password" class="text-sm font-medium">{{ $t('auth.password') }}</label>
          <input id="staff-password" v-model="password" type="password" autocomplete="current-password" required class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
        </div>
        <button
          type="submit"
          class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
          :disabled="submitting || email === '' || password === ''"
        >
          {{ submitting ? $t('auth.submitting') : $t('auth.continue') }}
        </button>
      </form>

      <form v-else class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm" novalidate data-step="mfa" @submit.prevent="submitCode">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ $t('auth.mfa_title') }}</h1>
          <p class="text-sm text-text-muted">{{ $t('auth.mfa_subtitle') }}</p>
        </div>
        <p v-if="errorKey" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">{{ $t(errorKey) }}</p>
        <div class="flex flex-col gap-1.5">
          <label for="staff-code" class="text-sm font-medium">{{ $t('auth.code') }}</label>
          <input
            id="staff-code"
            ref="codeInput"
            v-model="code"
            type="text"
            autocomplete="one-time-code"
            autocapitalize="off"
            spellcheck="false"
            maxlength="32"
            class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base tracking-wider"
          />
          <p class="text-[13px] text-text-muted">{{ $t('auth.recovery_hint') }}</p>
        </div>
        <button
          type="submit"
          class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60"
          :disabled="submitting || code.trim().length < 6"
        >
          {{ submitting ? $t('auth.submitting') : $t('auth.sign_in') }}
        </button>
        <button type="button" class="self-start text-sm text-primary hover:underline" @click="back">{{ $t('auth.back') }}</button>
      </form>
    </main>
  </div>
</template>
