<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { listAccountSessions, revokeAllAccountSessions, type AccountSession } from '@/api/account';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useSession } from '@/composables/useSession';

const router = useRouter();
const { clear, session } = useSession();
const sessions = ref<AccountSession[]>([]);
const loading = ref(true);
const error = ref<unknown>(null);
const password = ref('');
const code = ref('');
const saving = ref(false);

function formatActivity(value: string): string {
  return `${new Intl.DateTimeFormat(session.value?.user.locale ?? 'ru', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' }).format(new Date(value))} UTC`;
}

async function refresh(): Promise<void> {
  loading.value = true;
  error.value = null;

  try {
    sessions.value = await listAccountSessions();
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function revokeAll(): Promise<void> {
  saving.value = true;
  error.value = null;

  try {
    await revokeAllAccountSessions(password.value, code.value || null);
    clear();
    await router.push({ name: 'login' });
  } catch (caught) {
    error.value = caught;
  } finally {
    saving.value = false;
  }
}

onMounted(refresh);
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="sessions-title" data-panel="sessions">
    <h2 id="sessions-title" class="text-lg font-semibold">{{ $t('account.sessions.title') }}</h2>
    <p class="text-sm text-text-muted">{{ $t('account.sessions.description') }}</p>
    <p v-if="loading" class="text-sm text-text-muted">{{ $t('common.loading') }}</p>
    <ul v-else class="divide-y divide-border" aria-live="polite">
      <li v-for="(entry, index) in sessions" :key="index" class="flex flex-wrap items-center justify-between gap-2 py-3 text-sm">
        <span>
          <strong>{{ $t(`account.sessions.device.${entry.device}`) }}</strong>
          <span v-if="entry.current" class="ml-2 text-primary">{{ $t('account.sessions.current') }}</span>
          <span v-if="entry.ip_address" class="ml-2 text-text-muted">{{ entry.ip_address }}</span>
        </span>
        <time :datetime="entry.last_active_at" class="text-text-muted">{{ formatActivity(entry.last_active_at) }}</time>
      </li>
    </ul>
    <form class="flex flex-col gap-3 sm:max-w-md" @submit.prevent="revokeAll">
      <p class="text-sm">{{ $t('account.sessions.revoke_description') }}</p>
      <label for="sessions-password" class="text-sm font-medium">{{ $t('account.password.current') }}</label>
      <input id="sessions-password" v-model="password" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
      <template v-if="session?.user.mfa_enabled">
        <label for="sessions-code" class="text-sm font-medium">{{ $t('account.mfa.code_or_recovery') }}</label>
        <input id="sessions-code" v-model="code" type="text" autocomplete="one-time-code" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
      </template>
      <button type="submit" class="self-start rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="saving || !password">
        {{ $t('account.sessions.revoke_all') }}
      </button>
    </form>
    <ErrorNotice v-if="error" :error="error" />
  </section>
</template>
