<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Passkeys, UserCancelledError } from '@laravel/passkeys';
import { deletePasskey, listPasskeys, registerPasskey, type AccountPasskey } from '@/api/account';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useSession } from '@/composables/useSession';

const { session } = useSession();
const supported = ref(false);
const keys = ref<AccountPasskey[]>([]);
const name = ref('');
const password = ref('');
const code = ref('');
const busy = ref(false);
const loading = ref(true);
const error = ref<unknown>(null);
const pendingDelete = ref<number | null>(null);

async function load(): Promise<void> {
  loading.value = true;
  error.value = null;

  try {
    keys.value = await listPasskeys();
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function add(): Promise<void> {
  busy.value = true;
  error.value = null;

  try {
    await registerPasskey(name.value.trim(), password.value, code.value.trim() || null);
    name.value = '';
    password.value = '';
    code.value = '';
    await load();
  } catch (caught) {
    if (! (caught instanceof UserCancelledError)) error.value = caught;
  } finally {
    busy.value = false;
  }
}

async function remove(id: number): Promise<void> {
  busy.value = true;
  error.value = null;

  try {
    await deletePasskey(id, password.value, code.value.trim() || null);
    pendingDelete.value = null;
    password.value = '';
    code.value = '';
    await load();
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

onMounted(() => {
  supported.value = Passkeys.isSupported();
  void load();
});
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="passkeys-title" data-panel="passkeys">
    <h2 id="passkeys-title" class="text-lg font-semibold">{{ $t('account.passkeys.title') }}</h2>
    <p class="text-sm text-text-muted">{{ $t('account.passkeys.description') }}</p>
    <p v-if="!supported" class="text-sm text-text-muted">{{ $t('account.passkeys.unsupported') }}</p>
    <p v-if="loading" class="text-sm text-text-muted">{{ $t('common.loading') }}</p>
    <p v-else-if="keys.length === 0" class="text-sm text-text-muted">{{ $t('account.passkeys.empty') }}</p>
    <ul v-else class="divide-y divide-border">
      <li v-for="key in keys" :key="key.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
        <span><strong>{{ key.name }}</strong><span v-if="key.last_used_at" class="ml-2 text-text-muted">{{ $t('account.passkeys.last_used') }} {{ new Date(key.last_used_at).toLocaleDateString() }}</span></span>
        <button v-if="pendingDelete !== key.id" type="button" class="text-crit hover:underline" :disabled="busy" @click="pendingDelete = key.id">{{ $t('account.passkeys.delete') }}</button>
        <span v-else class="flex items-center gap-2">
          <button type="button" class="text-crit hover:underline disabled:opacity-60" :disabled="busy || !password" @click="remove(key.id)">{{ $t('account.passkeys.confirm_delete') }}</button>
          <button type="button" class="text-text-muted hover:underline" @click="pendingDelete = null">{{ $t('account.passkeys.cancel') }}</button>
        </span>
      </li>
    </ul>
    <form class="flex flex-col gap-3 sm:max-w-md" @submit.prevent="add">
      <label v-if="supported" for="passkey-name" class="text-sm font-medium">{{ $t('account.passkeys.name') }}</label>
      <input v-if="supported" id="passkey-name" v-model="name" type="text" maxlength="100" autocomplete="off" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
      <label for="passkey-password" class="text-sm font-medium">{{ $t('account.password.current') }}</label>
      <input id="passkey-password" v-model="password" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
      <template v-if="session?.user.mfa_enabled">
        <label for="passkey-code" class="text-sm font-medium">{{ $t('account.mfa.code_or_recovery') }}</label>
        <input id="passkey-code" v-model="code" type="text" autocomplete="one-time-code" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
      </template>
      <button v-if="supported" type="submit" class="self-start rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !name.trim() || !password">
        {{ $t('account.passkeys.add') }}
      </button>
    </form>
    <ErrorNotice v-if="error" :error="error" />
  </section>
</template>
