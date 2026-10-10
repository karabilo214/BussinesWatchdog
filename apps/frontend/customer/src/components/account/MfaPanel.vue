<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { beginMfaSetup, confirmMfa, disableMfa, fetchMfa, regenerateRecoveryCodes } from '@/api/account';
import type { MfaSetup, MfaState } from '@/api/types';
import CopyField from '@/components/CopyField.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { stepUpErrorKey } from '@/composables/errors';
import { useRole } from '@/composables/useRole';
import { useSession } from '@/composables/useSession';
import { StatusBadge } from '@bw/ui';

type Stage = 'idle' | 'password' | 'scan' | 'codes' | 'disable' | 'regenerate';

const { role } = useRole();
const { load: reloadSession } = useSession();

const state = ref<MfaState | null>(null);
const loadError = ref<unknown>(null);
const stage = ref<Stage>('idle');
const password = ref('');
const code = ref('');
const setup = ref<MfaSetup | null>(null);
const recoveryCodes = ref<string[]>([]);
const busy = ref(false);
const error = ref<unknown>(null);
const copied = ref(false);

const stepUpKey = computed(() => stepUpErrorKey(error.value));
const qrSource = computed(() => (setup.value ? `data:image/svg+xml;base64,${btoa(setup.value.qr_svg)}` : ''));
const recoveryText = computed(() => recoveryCodes.value.join('\n'));
const recoveryFile = computed(() => `data:text/plain;charset=utf-8,${encodeURIComponent(`Business Watchdog\n\n${recoveryText.value}\n`)}`);

async function load(): Promise<void> {
  try {
    state.value = await fetchMfa();
  } catch (caught) {
    loadError.value = caught;
  }
}

function open(next: Stage): void {
  stage.value = next;
  password.value = '';
  code.value = '';
  error.value = null;
}

async function run(action: () => Promise<void>): Promise<void> {
  busy.value = true;
  error.value = null;

  try {
    await action();
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

function start(): Promise<void> {
  return run(async () => {
    setup.value = await beginMfaSetup(password.value);
    password.value = '';
    stage.value = 'scan';
  });
}

function confirm(): Promise<void> {
  return run(async () => {
    const result = await confirmMfa(code.value.trim());
    state.value = { enabled: result.enabled, recovery_codes_remaining: result.recovery_codes_remaining };
    recoveryCodes.value = result.recovery_codes;
    setup.value = null;
    code.value = '';
    stage.value = 'codes';
    await reloadSession(true);
  });
}

function regenerate(): Promise<void> {
  return run(async () => {
    const result = await regenerateRecoveryCodes(password.value, code.value.trim());
    state.value = { enabled: result.enabled, recovery_codes_remaining: result.recovery_codes_remaining };
    recoveryCodes.value = result.recovery_codes;
    stage.value = 'codes';
  });
}

function disable(): Promise<void> {
  return run(async () => {
    state.value = await disableMfa(password.value, code.value.trim());
    open('idle');
    await reloadSession(true);
  });
}

async function copyCodes(): Promise<void> {
  try {
    await navigator.clipboard.writeText(recoveryText.value);
    copied.value = true;
  } catch {
    copied.value = false;
  }
}

function finish(): void {
  recoveryCodes.value = [];
  copied.value = false;
  open('idle');
}

onMounted(load);
</script>

<template>
  <section class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" aria-labelledby="mfa-title" data-panel="mfa">
    <div class="flex flex-wrap items-center gap-2">
      <h2 id="mfa-title" class="text-lg font-semibold">{{ $t('account.mfa.title') }}</h2>
      <template v-if="state">
        <StatusBadge v-if="state.enabled" tone="ok" :label="$t('account.mfa.on')" />
        <StatusBadge v-else tone="unknown" :label="$t('account.mfa.off')" />
      </template>
    </div>
    <ErrorNotice v-if="loadError" :error="loadError" />

    <template v-else-if="state">
      <p class="text-sm text-text-muted">{{ $t('account.mfa.intro') }}</p>

      <template v-if="stage === 'idle'">
        <p v-if="!state.enabled && role === 'owner'" class="rounded-md border border-warn-border bg-warn-soft px-3 py-2 text-sm" data-owner-hint>{{ $t('account.mfa.owner_hint') }}</p>
        <p v-if="state.enabled" class="text-sm" :class="state.recovery_codes_remaining <= 3 ? 'text-warn' : ''">
          {{ $t('account.mfa.remaining', { count: state.recovery_codes_remaining }) }}
        </p>
        <div class="flex flex-wrap gap-3">
          <button v-if="!state.enabled" type="button" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover" @click="open('password')">{{ $t('account.mfa.enable') }}</button>
          <template v-else>
            <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium hover:bg-surface-muted" @click="open('regenerate')">{{ $t('account.mfa.regenerate') }}</button>
            <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium text-crit hover:bg-crit-soft" @click="open('disable')">{{ $t('account.mfa.disable') }}</button>
          </template>
        </div>
      </template>

      <form v-else-if="stage === 'password'" class="flex flex-col gap-4 sm:max-w-md" @submit.prevent="start">
        <div class="flex flex-col gap-1.5">
          <label for="mfa-password" class="text-sm font-medium">{{ $t('account.password.current') }}</label>
          <input id="mfa-password" v-model="password" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
        </div>
        <p v-if="stepUpKey" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">{{ $t(stepUpKey) }}</p>
        <ErrorNotice v-else-if="error" :error="error" />
        <div class="flex flex-wrap gap-3">
          <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || password === ''">{{ $t('common.continue') }}</button>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium" @click="open('idle')">{{ $t('common.cancel') }}</button>
        </div>
      </form>

      <form v-else-if="stage === 'scan' && setup" class="flex flex-col gap-4" @submit.prevent="confirm">
        <ol class="flex list-decimal flex-col gap-1 pl-5 text-sm">
          <li>{{ $t('account.mfa.step_app') }}</li>
          <li>{{ $t('account.mfa.step_scan') }}</li>
          <li>{{ $t('account.mfa.step_code') }}</li>
        </ol>
        <div class="flex flex-wrap items-start gap-5">
          <img :src="qrSource" :alt="$t('account.mfa.qr_alt')" width="200" height="200" class="rounded-md border border-border bg-white p-2" data-qr />
          <div class="flex min-w-[14rem] flex-1 flex-col gap-4">
            <CopyField id="mfa-secret" :label="$t('account.mfa.secret')" :value="setup.secret" />
            <div class="flex flex-col gap-1.5">
              <label for="mfa-code" class="text-sm font-medium">{{ $t('account.mfa.code') }}</label>
              <input id="mfa-code" v-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base tracking-widest sm:max-w-[10rem]" />
            </div>
          </div>
        </div>
        <ErrorNotice v-if="error" :error="error" />
        <div class="flex flex-wrap gap-3">
          <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !/^\d{6}$/.test(code.trim())">{{ $t('account.mfa.confirm') }}</button>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium" @click="open('idle')">{{ $t('common.cancel') }}</button>
        </div>
      </form>

      <div v-else-if="stage === 'codes'" class="flex flex-col gap-4" data-recovery-codes>
        <p class="rounded-md border border-warn-border bg-warn-soft px-3 py-2 text-sm">{{ $t('account.mfa.codes_once') }}</p>
        <ul class="grid grid-cols-2 gap-2 rounded-lg border border-border bg-surface-muted p-4 font-mono text-sm sm:max-w-md">
          <li v-for="item in recoveryCodes" :key="item">{{ item }}</li>
        </ul>
        <div class="flex flex-wrap items-center gap-3">
          <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium hover:bg-surface-muted" @click="copyCodes">{{ $t('account.mfa.copy_codes') }}</button>
          <a :href="recoveryFile" download="business-watchdog-recovery-codes.txt" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium hover:bg-surface-muted">{{ $t('account.mfa.download_codes') }}</a>
          <span v-if="copied" class="text-sm text-ok" role="status">{{ $t('account.mfa.copied') }}</span>
        </div>
        <button type="button" class="self-start rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover" @click="finish">{{ $t('account.mfa.saved_codes') }}</button>
      </div>

      <form v-else class="flex flex-col gap-4 sm:max-w-md" :data-step-up="stage" @submit.prevent="stage === 'disable' ? disable() : regenerate()">
        <p class="text-sm">{{ stage === 'disable' ? $t('account.mfa.disable_warning') : $t('account.mfa.regenerate_warning') }}</p>
        <div class="flex flex-col gap-1.5">
          <label for="mfa-stepup-password" class="text-sm font-medium">{{ $t('account.password.current') }}</label>
          <input id="mfa-stepup-password" v-model="password" type="password" autocomplete="current-password" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-base" />
        </div>
        <div class="flex flex-col gap-1.5">
          <label for="mfa-stepup-code" class="text-sm font-medium">{{ $t('account.mfa.code_or_recovery') }}</label>
          <input id="mfa-stepup-code" v-model="code" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base" />
        </div>
        <p v-if="stepUpKey" role="alert" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit">{{ $t(stepUpKey) }}</p>
        <ErrorNotice v-else-if="error" :error="error" />
        <div class="flex flex-wrap gap-3">
          <button
            type="submit"
            class="rounded-md px-4 py-2 text-sm font-semibold disabled:opacity-60"
            :class="stage === 'disable' ? 'bg-crit-solid text-white' : 'bg-primary text-text-inverse hover:bg-primary-hover'"
            :disabled="busy || password === '' || code.trim().length < 6"
          >
            {{ stage === 'disable' ? $t('account.mfa.disable') : $t('account.mfa.regenerate') }}
          </button>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-4 py-2 text-sm font-medium" @click="open('idle')">{{ $t('common.cancel') }}</button>
        </div>
      </form>
    </template>
  </section>
</template>
