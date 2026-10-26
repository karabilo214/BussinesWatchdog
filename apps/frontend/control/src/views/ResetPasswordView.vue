<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ApiError } from '@bw/api-client';
import { LocaleSwitch } from '@bw/i18n';
import { completeStaffPasswordReset } from '@/api';

const route = useRoute();
const email = typeof route.query.email === 'string' ? route.query.email : '';
const token = typeof route.query.token === 'string' ? route.query.token : '';
const password = ref('');
const repeat = ref('');
const busy = ref(false);
const done = ref(false);
const errorKey = ref<string | null>(email === '' || token === '' ? 'profile.reset.broken' : null);
const ready = computed(() => password.value.length >= 12 && password.value === repeat.value && email !== '' && token !== '');

async function submit(): Promise<void> {
  busy.value = true;
  errorKey.value = null;

  try {
    await completeStaffPasswordReset(email, token, password.value);
    done.value = true;
  } catch (caught) {
    errorKey.value = caught instanceof ApiError && caught.code === 'password_reset_invalid' ? 'profile.reset.invalid' : caught instanceof ApiError && caught.status === 429 ? 'auth.rate_limited' : 'auth.generic';
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="flex min-h-full flex-col px-4 py-8 sm:px-6">
    <div class="flex justify-end"><LocaleSwitch /></div>
    <main class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-6 py-10">
      <span class="flex items-center gap-2 text-lg font-semibold text-brand-800"><img src="/favicon.png" alt="" width="32" height="32" />{{ $t('app.name') }}</span>
      <div class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-6 shadow-sm" data-reset>
        <h1 class="text-2xl font-semibold">{{ $t('profile.reset.title') }}</h1>
        <template v-if="done">
          <p role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-reset-done>{{ $t('profile.reset.done') }}</p>
          <RouterLink :to="{ name: 'login' }" class="self-start rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover">{{ $t('auth.submit') }}</RouterLink>
        </template>
        <form v-else class="flex flex-col gap-4 text-sm" novalidate @submit.prevent="submit">
          <p class="text-text-muted">{{ $t('profile.reset.subtitle', { email }) }}</p>
          <label class="flex flex-col gap-1.5"><span class="font-medium">{{ $t('profile.new_password') }}</span><input v-model="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="password" /></label>
          <label class="flex flex-col gap-1.5"><span class="font-medium">{{ $t('profile.repeat_password') }}</span><input v-model="repeat" type="password" autocomplete="new-password" maxlength="128" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="repeat" /></label>
          <p v-if="errorKey" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-crit" data-reset-error>{{ $t(errorKey) }}</p>
          <button type="submit" class="self-start rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !ready">{{ $t('profile.reset.submit') }}</button>
        </form>
      </div>
    </main>
  </div>
</template>
