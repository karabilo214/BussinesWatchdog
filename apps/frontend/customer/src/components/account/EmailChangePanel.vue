<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { cancelEmailChange, requestEmailChange, resendEmailChange } from '@/api/account';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { stepUpErrorKey } from '@/composables/errors';
import { useFormat } from '@/composables/useFormat';
import { useSession } from '@/composables/useSession';

const { session, load } = useSession();
const { dateTime } = useFormat();

const open = ref(false);
const form = reactive({ email: '', password: '', code: '' });
const busy = ref(false);
const error = ref<unknown>(null);
const resent = ref(false);
const needsCode = computed(() => session.value?.user.mfa_enabled === true);
const pending = computed(() => session.value?.user.pending_email_change ?? null);
const stepUpKey = computed(() => stepUpErrorKey(error.value));
const valid = computed(() => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim()) && form.password !== '' && (!needsCode.value || form.code.trim().length >= 6));

async function run(action: () => Promise<unknown>): Promise<boolean> {
  busy.value = true;
  error.value = null;

  try {
    await action();
    await load(true);

    return true;
  } catch (caught) {
    error.value = caught;

    return false;
  } finally {
    busy.value = false;
  }
}

async function submit(): Promise<void> {
  resent.value = false;

  if (await run(() => requestEmailChange(form.email.trim(), form.password, needsCode.value ? form.code.trim() : null))) {
    open.value = false;
    Object.assign(form, { email: '', password: '', code: '' });
  } else {
    form.password = '';
    form.code = '';
  }
}

async function resend(): Promise<void> {
  resent.value = await run(() => resendEmailChange());
}

async function cancel(): Promise<void> {
  resent.value = false;
  await run(() => cancelEmailChange());
}
</script>

<template>
  <div class="flex flex-col gap-3 text-sm" data-email-change>
    <div v-if="pending" class="flex flex-col gap-2 rounded-md border border-warn-border bg-warn-soft p-3" data-email-change-pending>
      <p>{{ $t('account.email_change.pending', { email: pending.email, time: dateTime(pending.expires_at) }) }}</p>
      <p class="text-[13px]">{{ $t('account.email_change.pending_hint') }}</p>
      <div class="flex flex-wrap items-center gap-3">
        <button type="button" class="font-medium text-primary hover:underline disabled:opacity-60" :disabled="busy" data-email-change-resend @click="resend">{{ $t('account.email_change.resend') }}</button>
        <button type="button" class="font-medium text-crit hover:underline disabled:opacity-60" :disabled="busy" data-email-change-cancel @click="cancel">{{ $t('account.email_change.cancel') }}</button>
        <span v-if="resent" class="text-ok" role="status">{{ $t('account.email_change.resent') }}</span>
      </div>
    </div>

    <button v-if="!open" type="button" class="self-start font-medium text-primary hover:underline" data-email-change-open @click="open = true; error = null">{{ $t('account.email_change.open') }}</button>
    <form v-else class="flex flex-col gap-3 rounded-md border border-border bg-surface-muted p-3 sm:max-w-md" novalidate data-email-change-form @submit.prevent="submit">
      <p class="text-[13px] text-text-muted">{{ $t('account.email_change.explain') }}</p>
      <label class="flex flex-col gap-1.5 font-medium">{{ $t('account.email_change.new_email') }}<input v-model="form.email" type="email" autocomplete="email" maxlength="255" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal" /></label>
      <label class="flex flex-col gap-1.5 font-medium">{{ $t('account.password.current') }}<input v-model="form.password" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base font-normal" /></label>
      <label v-if="needsCode" class="flex flex-col gap-1.5 font-medium">{{ $t('account.mfa.code_or_recovery') }}<input v-model="form.code" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base font-normal" /></label>
      <p v-if="stepUpKey" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-crit">{{ $t(stepUpKey) }}</p>
      <ErrorNotice v-else-if="error" :error="error" />
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="rounded-md bg-primary px-3 py-1.5 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !valid">{{ $t('account.email_change.submit') }}</button>
        <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5" @click="open = false">{{ $t('common.cancel') }}</button>
      </div>
    </form>
    <ErrorNotice v-if="!open && error" :error="error" />
  </div>
</template>
