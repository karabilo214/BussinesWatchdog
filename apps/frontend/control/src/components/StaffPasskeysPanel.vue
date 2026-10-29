<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { passkeyCancelled, passkeysSupported, deleteStaffPasskey, listStaffPasskeys, addStaffPasskey, type StaffPasskey } from '@/api/passkeys';
import ErrorNotice from '@/components/ErrorNotice.vue';

const supported = ref(false);
const keys = ref<StaffPasskey[]>([]);
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
    keys.value = await listStaffPasskeys();
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function add(): Promise<void> {
  if (busy.value) return;
  busy.value = true;
  error.value = null;

  try {
    await addStaffPasskey(name.value.trim(), password.value, code.value.trim());
    name.value = '';
    password.value = '';
    code.value = '';
    await load();
  } catch (caught) {
    if (!passkeyCancelled(caught)) error.value = caught;
  } finally {
    password.value = '';
    code.value = '';
    busy.value = false;
  }
}

async function remove(id: number): Promise<void> {
  if (busy.value) return;
  busy.value = true;
  error.value = null;

  try {
    await deleteStaffPasskey(id, password.value, code.value.trim());
    pendingDelete.value = null;
    password.value = '';
    code.value = '';
    await load();
  } catch (caught) {
    error.value = caught;
  } finally {
    password.value = '';
    code.value = '';
    busy.value = false;
  }
}

onMounted(() => {
  supported.value = passkeysSupported();
  void load();
});
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="passkeys-title" data-panel="passkeys">
    <h2 id="passkeys-title" class="text-lg font-semibold">{{ $t('passkeys.title') }}</h2>
    <p class="text-sm text-text-muted">{{ $t('passkeys.description') }}</p>
    <p class="text-sm text-text-muted">{{ $t('passkeys.backup') }}</p>
    <p v-if="!supported" class="text-sm text-text-muted">{{ $t('passkeys.unsupported') }}</p>
    <p v-if="loading" class="text-sm text-text-muted">{{ $t('common.loading') }}</p>
    <p v-else-if="keys.length === 0" class="text-sm text-text-muted">{{ $t('passkeys.empty') }}</p>
    <ul v-else class="divide-y divide-border">
      <li v-for="key in keys" :key="key.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
        <span><strong>{{ key.name }}</strong><span v-if="key.last_used_at" class="ml-2 text-text-muted">{{ $t('passkeys.last_used') }} {{ new Date(key.last_used_at).toLocaleDateString() }}</span></span>
        <button v-if="pendingDelete !== key.id" type="button" class="text-crit hover:underline" :disabled="busy" @click="pendingDelete = key.id">{{ $t('passkeys.delete') }}</button>
        <span v-else class="flex items-center gap-2">
          <button type="button" class="text-crit hover:underline disabled:opacity-60" :disabled="busy || !password || !code.trim()" @click="remove(key.id)">{{ $t('passkeys.confirm_delete') }}</button>
          <button type="button" class="text-text-muted hover:underline" :disabled="busy" @click="pendingDelete = null">{{ $t('passkeys.cancel') }}</button>
        </span>
      </li>
    </ul>
    <form v-if="supported || keys.length > 0" class="flex flex-col gap-3 sm:max-w-md" @submit.prevent="add">
      <label v-if="supported" for="passkey-name" class="text-sm font-medium">{{ $t('passkeys.name') }}</label>
      <input v-if="supported" id="passkey-name" v-model="name" type="text" maxlength="100" autocomplete="off" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
      <label for="passkey-password" class="text-sm font-medium">{{ $t('profile.current_password') }}</label>
      <input id="passkey-password" v-model="password" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
      <label for="passkey-code" class="text-sm font-medium">{{ $t('auth.code') }}</label>
      <input id="passkey-code" v-model="code" type="text" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
      <button v-if="supported" type="submit" class="self-start rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || loading || !name.trim() || !password || !code.trim()">
        {{ $t('passkeys.add') }}
      </button>
    </form>
    <ErrorNotice v-if="error" :error="error" />
  </section>
</template>
