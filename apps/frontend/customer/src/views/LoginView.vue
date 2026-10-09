<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import { useSession } from '@/composables/useSession';
import AuthLayout from '@/layouts/AuthLayout.vue';

const route = useRoute();
const router = useRouter();
const { signIn } = useSession();
const email = ref('');
const password = ref('');
const remember = ref(false);
const submitting = ref(false);
const errorKey = ref<string | null>(route.query.expired === '1' ? 'auth.session_expired' : null);

async function submit(): Promise<void> {
  submitting.value = true;
  errorKey.value = null;

  try {
    await signIn(email.value.trim(), password.value, remember.value);
    const target = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : { name: 'overview' };
    await router.replace(target);
  } catch (error) {
    if (error instanceof ApiError && error.status === 429) {
      errorKey.value = 'auth.rate_limited';
    } else if (error instanceof ApiError && error.status === 0) {
      errorKey.value = 'common.error_network';
    } else if (error instanceof ApiError && error.status === 422) {
      errorKey.value = 'auth.failed';
    } else {
      errorKey.value = 'common.error_generic';
    }
  } finally {
    password.value = '';
    submitting.value = false;
  }
}
</script>

<template>
  <AuthLayout>
    <form class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm" novalidate @submit.prevent="submit">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('auth.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('auth.subtitle') }}</p>
      </div>

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
  </AuthLayout>
</template>
