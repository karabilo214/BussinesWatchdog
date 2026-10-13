<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { LocaleSwitch } from '@bw/i18n';
import { confirmStaffInvitation, lookupStaffInvitation, startStaffInvitation, type StaffRole } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';

const route = useRoute();
const { dateTime } = useFormat();
const token = typeof route.query.token === 'string' ? route.query.token : '';
const invitation = ref<{ email: string; role: StaffRole; expires_at: string; account_exists: boolean } | null>(null);
const step = ref<'loading' | 'invalid' | 'account' | 'mfa' | 'done'>('loading');
const setup = ref<{ mfa_enabled: boolean; otpauth_uri?: string; secret?: string; qr_svg?: string } | null>(null);
const recoveryCodes = ref<string[]>([]);
const busy = ref(false);
const error = ref<unknown>(null);
const form = reactive({ name: '', password: '', repeat: '', code: '' });

const accountReady = computed(() => form.password.length >= 12 && (invitation.value?.account_exists || (form.name.trim() !== '' && form.password === form.repeat)));

onMounted(async () => {
  if (token === '') {
    step.value = 'invalid';

    return;
  }

  try {
    invitation.value = await lookupStaffInvitation(token);
    step.value = 'account';
  } catch {
    step.value = 'invalid';
  }
});

async function start(): Promise<void> {
  if (!accountReady.value || busy.value) return;
  busy.value = true;
  error.value = null;

  try {
    setup.value = await startStaffInvitation({ token, password: form.password, ...(invitation.value?.account_exists ? {} : { name: form.name.trim() }) });
    step.value = 'mfa';
  } catch (caught) {
    error.value = caught;
  } finally {
    form.password = '';
    form.repeat = '';
    busy.value = false;
  }
}

async function confirm(): Promise<void> {
  if (form.code.trim().length < 6 || busy.value) return;
  busy.value = true;
  error.value = null;

  try {
    recoveryCodes.value = (await confirmStaffInvitation({ token, code: form.code.trim() })).recovery_codes;
    step.value = 'done';
  } catch (caught) {
    error.value = caught;
  } finally {
    form.code = '';
    busy.value = false;
  }
}
</script>

<template>
  <div class="flex min-h-full flex-col px-4 py-8 sm:px-6">
    <div class="flex justify-end">
      <LocaleSwitch />
    </div>
    <main class="mx-auto flex w-full max-w-md flex-1 flex-col justify-center gap-6 py-10">
      <span class="text-lg font-semibold text-brand-800">{{ $t('app.name') }}</span>
      <p v-if="step === 'loading'" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>

      <section v-else-if="step === 'invalid'" class="flex flex-col gap-2 rounded-xl border border-border bg-surface p-6" data-step="invalid">
        <h1 class="text-2xl font-semibold">{{ $t('staff_join.invalid_title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('staff_join.invalid') }}</p>
      </section>

      <form v-else-if="step === 'account' && invitation" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-6 shadow-sm" data-step="account" @submit.prevent="start">
        <h1 class="text-2xl font-semibold">{{ $t('staff_join.title') }}</h1>
        <p class="text-sm text-text-muted">{{ $t('staff_join.offer', { email: invitation.email, role: $t(`role.${invitation.role}`), date: dateTime(invitation.expires_at) }) }}</p>
        <ErrorNotice v-if="error" :error="error" />
        <p v-if="invitation.account_exists" class="text-sm">{{ $t('staff_join.existing') }}</p>
        <label v-else class="flex flex-col gap-1.5 text-sm">
          <span class="font-medium">{{ $t('staff_join.name') }}</span>
          <input v-model="form.name" maxlength="100" autocomplete="name" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" data-field="name" />
        </label>
        <label class="flex flex-col gap-1.5 text-sm">
          <span class="font-medium">{{ $t(invitation.account_exists ? 'auth.password' : 'staff_join.new_password') }}</span>
          <input v-model="form.password" type="password" :autocomplete="invitation.account_exists ? 'current-password' : 'new-password'" maxlength="128" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" data-field="password" />
        </label>
        <label v-if="!invitation.account_exists" class="flex flex-col gap-1.5 text-sm">
          <span class="font-medium">{{ $t('staff_join.repeat') }}</span>
          <input v-model="form.repeat" type="password" autocomplete="new-password" maxlength="128" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" data-field="repeat" />
          <span v-if="form.repeat !== '' && form.repeat !== form.password" class="text-crit">{{ $t('staff_join.mismatch') }}</span>
        </label>
        <button type="submit" class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !accountReady" data-action="continue">{{ $t('auth.continue') }}</button>
      </form>

      <form v-else-if="step === 'mfa' && setup" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-6 shadow-sm" data-step="mfa" @submit.prevent="confirm">
        <h1 class="text-2xl font-semibold">{{ $t('staff_join.mfa_title') }}</h1>
        <template v-if="!setup.mfa_enabled">
          <p class="text-sm text-text-muted">{{ $t('staff_join.mfa_setup') }}</p>
          <div v-if="setup.qr_svg" class="mx-auto w-48 rounded-md border border-border bg-white p-2 [&>svg]:h-auto [&>svg]:w-full" data-qr v-html="setup.qr_svg" />
          <p class="break-all text-center font-mono text-sm" data-secret>{{ setup.secret }}</p>
        </template>
        <p v-else class="text-sm text-text-muted">{{ $t('staff_join.mfa_existing') }}</p>
        <ErrorNotice v-if="error" :error="error" />
        <label class="flex flex-col gap-1.5 text-sm">
          <span class="font-medium">{{ $t('auth.code') }}</span>
          <input v-model="form.code" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base tracking-wider" data-field="code" />
        </label>
        <button type="submit" class="rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || form.code.trim().length < 6" data-action="join">{{ $t('staff_join.join') }}</button>
      </form>

      <section v-else-if="step === 'done'" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-6" data-step="done">
        <h1 class="text-2xl font-semibold">{{ $t('staff_join.done_title') }}</h1>
        <template v-if="recoveryCodes.length > 0">
          <p class="text-sm">{{ $t('staff_join.recovery') }}</p>
          <ul class="grid grid-cols-2 gap-1 rounded-md bg-surface-muted p-3 font-mono text-sm" data-recovery>
            <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
          </ul>
        </template>
        <RouterLink :to="{ name: 'login' }" class="self-start rounded-md bg-primary px-4 py-2.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover" data-action="to-login">{{ $t('staff_join.sign_in') }}</RouterLink>
      </section>
    </main>
  </div>
</template>
