<script setup lang="ts">
import { ref } from 'vue';
import { requestPasswordReset } from '@/api/account';
import ErrorNotice from '@/components/ErrorNotice.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

const email = ref('');
const sending = ref(false);
const sent = ref(false);
const error = ref<unknown>(null);

async function submit(): Promise<void> {
  sending.value = true;
  error.value = null;

  try {
    await requestPasswordReset(email.value.trim());
    sent.value = true;
  } catch (caught) {
    error.value = caught;
  } finally {
    sending.value = false;
  }
}
</script>

<template>
  <AuthLayout>
    <div class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('account.forgot.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('account.forgot.subtitle') }}</p>
      </div>
      <p v-if="sent" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-sent>{{ $t('account.forgot.sent') }}</p>
      <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <ErrorNotice v-if="error" :error="error" />
        <div class="flex flex-col gap-1.5">
          <label for="forgot-email" class="text-sm font-medium">{{ $t('auth.email') }}</label>
          <input id="forgot-email" v-model="email" type="email" autocomplete="username" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
        </div>
        <button type="submit" class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="sending || !/^[^\s@]+@[^\s@]+$/.test(email.trim())">
          {{ $t('account.forgot.submit') }}
        </button>
      </form>
      <RouterLink :to="{ name: 'login' }" class="text-sm text-primary hover:underline">{{ $t('account.back_to_login') }}</RouterLink>
    </div>
  </AuthLayout>
</template>
