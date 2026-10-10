<script setup lang="ts">
import { computed, ref } from 'vue';
import { resendChannelCode, testChannel, updateChannel, verifyChannel } from '@/api/notifications';
import type { NotificationChannel, Store } from '@/api/types';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';
import { StatusBadge } from '@bw/ui';
import PreferencesForm from './PreferencesForm.vue';
import { CHANNEL_TONES, channelState } from './tones';

const props = defineProps<{ channel: NotificationChannel; stores: Store[]; isOwner: boolean }>();
const emit = defineEmits<{ changed: [channel: NotificationChannel]; tested: [] }>();
const { dateTime } = useFormat();

const mode = ref<'idle' | 'settings' | 'address'>('idle');
const code = ref('');
const email = ref('');
const busy = ref(false);
const error = ref<unknown>(null);
const notice = ref<string | null>(null);
const prefs = ref<InstanceType<typeof PreferencesForm> | null>(null);

const state = computed(() => channelState(props.channel));
const storeNames = computed(() => {
  const ids = props.channel.effective_preferences.store_ids;

  return ids === null ? null : props.stores.filter((store) => ids.includes(store.id)).map((store) => store.name);
});

async function act(action: () => Promise<NotificationChannel | void>, success: string | null = null): Promise<void> {
  busy.value = true;
  error.value = null;
  notice.value = null;

  try {
    const result = await action();

    if (result) emit('changed', result);

    notice.value = success;
    mode.value = 'idle';
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

function verify(): Promise<void> {
  return act(() => verifyChannel(props.channel.id, code.value.trim()), 'notifications.channel.verified');
}

function resend(): Promise<void> {
  return act(() => resendChannelCode(props.channel.id), 'notifications.channel.code_sent');
}

function toggle(): Promise<void> {
  return act(() => updateChannel(props.channel.id, { enabled: !props.channel.enabled }));
}

function sendTest(): Promise<void> {
  return act(async () => {
    await testChannel(props.channel.id);
    emit('tested');
  }, 'notifications.channel.test_queued');
}

function saveSettings(): Promise<void> {
  const value = prefs.value?.value();

  return value === undefined ? Promise.resolve() : act(() => updateChannel(props.channel.id, { preferences: value }), 'common.saved');
}

function changeAddress(): Promise<void> {
  return act(() => updateChannel(props.channel.id, { destination_email: email.value.trim() }), 'notifications.channel.code_sent');
}
</script>

<template>
  <li class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5" :data-channel-id="channel.id">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="flex min-w-0 flex-col gap-0.5">
        <span class="font-semibold">{{ channel.label }}</span>
        <span class="break-all font-mono text-[13px] text-text-muted">{{ channel.destination_masked }}</span>
      </div>
      <StatusBadge :tone="CHANNEL_TONES[state]" :label="$t(`notifications.channel.state.${state}`)" />
    </div>

    <p class="text-[13px] text-text-muted">
      {{ $t('notifications.channel.summary', { severity: $t(`notifications.prefs.severity.${channel.effective_preferences.min_severity}`) }) }}
      <template v-if="channel.effective_preferences.quiet_hours">
        · {{ $t('notifications.channel.quiet', { start: channel.effective_preferences.quiet_hours.start, end: channel.effective_preferences.quiet_hours.end, zone: channel.effective_preferences.timezone }) }}
      </template>
      · {{ storeNames === null ? $t('notifications.prefs.all_stores') : storeNames.join(', ') }}
    </p>

    <p v-if="state === 'failing'" class="rounded-md border border-crit-border bg-crit-soft px-3 py-2 text-sm text-crit" data-health>
      {{ $t('notifications.channel.failing', { time: dateTime(channel.health.last_dead_letter_at ?? null) }) }}
    </p>
    <p v-else-if="channel.health.last_success_at" class="text-[13px] text-text-muted">{{ $t('notifications.channel.last_success', { time: dateTime(channel.health.last_success_at) }) }}</p>

    <form v-if="state === 'unverified'" class="flex flex-col gap-2 rounded-lg border border-warn-border bg-warn-soft p-4" @submit.prevent="verify">
      <label :for="`code-${channel.id}`" class="text-sm font-medium">{{ $t('notifications.channel.code_label') }}</label>
      <div class="flex flex-wrap gap-2">
        <input
          :id="`code-${channel.id}`"
          v-model="code"
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          class="w-36 rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-base tracking-widest"
        />
        <button type="submit" class="rounded-md bg-primary px-3 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !/^\d{6}$/.test(code.trim())">
          {{ $t('notifications.channel.confirm') }}
        </button>
        <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy" @click="resend">
          {{ $t('notifications.channel.resend') }}
        </button>
      </div>
      <p class="text-[13px] text-text-muted">{{ $t('notifications.channel.code_hint') }}</p>
    </form>

    <div class="flex flex-wrap gap-2">
      <button
        v-if="state !== 'unverified'"
        type="button"
        class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium hover:bg-surface-muted disabled:opacity-60"
        :disabled="busy"
        @click="toggle"
      >
        {{ channel.enabled ? $t('notifications.channel.disable') : $t('notifications.channel.enable') }}
      </button>
      <button
        v-if="state !== 'unverified'"
        type="button"
        class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft disabled:opacity-60"
        :disabled="busy"
        @click="sendTest"
      >
        {{ $t('notifications.channel.test') }}
      </button>
      <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft" :aria-expanded="mode === 'settings'" @click="mode = mode === 'settings' ? 'idle' : 'settings'">
        {{ $t('notifications.channel.settings') }}
      </button>
      <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary-soft" :aria-expanded="mode === 'address'" @click="mode = mode === 'address' ? 'idle' : 'address'; email = ''">
        {{ $t('notifications.channel.change_address') }}
      </button>
    </div>

    <form v-if="mode === 'settings'" class="flex flex-col gap-4 border-t border-border pt-4" @submit.prevent="saveSettings">
      <PreferencesForm ref="prefs" :id-prefix="`prefs-${channel.id}`" :preferences="channel.effective_preferences" :stores="stores" :is-owner="isOwner" />
      <div>
        <button type="submit" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy">{{ $t('common.save') }}</button>
      </div>
    </form>

    <form v-if="mode === 'address'" class="flex flex-col gap-2 border-t border-border pt-4" @submit.prevent="changeAddress">
      <label :for="`email-${channel.id}`" class="text-sm font-medium">{{ $t('notifications.channel.new_address') }}</label>
      <input :id="`email-${channel.id}`" v-model="email" type="email" autocomplete="email" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm sm:max-w-sm" />
      <p class="text-[13px] text-text-muted">{{ $t('notifications.channel.address_note') }}</p>
      <div>
        <button type="submit" class="rounded-md bg-primary px-3 py-1.5 text-sm font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())">
          {{ $t('notifications.channel.send_code') }}
        </button>
      </div>
    </form>

    <p v-if="notice" class="text-sm text-ok" role="status">{{ $t(notice) }}</p>
    <ErrorNotice v-if="error" :error="error" />
  </li>
</template>
