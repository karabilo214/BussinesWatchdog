<script setup lang="ts">
import { computed, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { completePasswordReset } from '@/api/account';
import ErrorNotice from '@/components/ErrorNotice.vue';
import PasswordFields from '@/components/account/PasswordFields.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';

const route = useRoute();
const router = useRouter();
const token = typeof route.query.token === 'string' ? route.query.token : '';
const email = typeof route.query.email === 'string' ? route.query.email : '';
const password = ref('');
const valid = ref(false);
const saving = ref(false);
const error = ref<unknown>(null);
const linkBroken = computed(() => token === '' || email === '');

async function submit(): Promise<void> {
  saving.value = true;
  error.value = null;

  try {
    await completePasswordReset(email, token, password.value);
    await router.replace({ name: 'login', query: { reset: '1' } });
  } catch (caught) {
    error.value = caught;
  } finally {
    saving.value = false;
  }
}
</script>

<template>
  <AuthLayout>
    <div class="flex flex-col gap-5 rounded-xl border border-border bg-surface p-6 shadow-sm">
      <div class="flex flex-col gap-1">
        <h1 class="text-2xl font-semibold">{{ $t('account.reset.title') }}</h1>
        <p class="text-sm text-text-muted">{{ linkBroken ? $t('account.reset.broken') : $t('account.reset.subtitle', { email }) }}</p>
      </div>
      <form v-if="!linkBroken" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
        <ErrorNotice v-if="error" :error="error" />
        <PasswordFields id-prefix="reset" @update="(value, ok) => { password = value; valid = ok; }" />
        <button type="submit" class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="saving || !valid">
          {{ $t('account.reset.submit') }}
        </button>
      </form>
      <RouterLink :to="{ name: linkBroken ? 'forgot-password' : 'login' }" class="text-sm text-primary hover:underline">
        {{ linkBroken ? $t('account.reset.request_new') : $t('account.back_to_login') }}
      </RouterLink>
    </div>
  </AuthLayout>
</template>
