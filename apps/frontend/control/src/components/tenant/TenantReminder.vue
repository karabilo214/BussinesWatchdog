<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { previewTenantReminder, sendTenantReminder, type ReminderInput, type ReminderPreview, type ReminderTopic } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';

const TOPICS: ReminderTopic[] = ['subscription', 'payment', 'other'];

const props = defineProps<{ tenantId: string }>();
const emit = defineEmits<{ close: []; sent: [recipients: number] }>();

const { dateTime } = useFormat();
const form = reactive({ topic: 'subscription' as ReminderTopic, subject: '', message: '' });
const preview = ref<ReminderPreview | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);

const ready = computed(() => form.topic !== 'other' || (form.subject.trim().length >= 3 && form.message.trim().length >= 10));

function input(): ReminderInput {
  return form.topic === 'other' ? { topic: form.topic, subject: form.subject.trim(), message: form.message.trim() } : { topic: form.topic };
}

function reset(): void {
  preview.value = null;
  error.value = null;
}

async function showPreview(): Promise<void> {
  if (!ready.value || busy.value) return;
  busy.value = true;
  error.value = null;

  try {
    preview.value = await previewTenantReminder(props.tenantId, input());
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

async function send(): Promise<void> {
  if (preview.value === null || busy.value) return;
  busy.value = true;
  error.value = null;

  try {
    const result = await sendTenantReminder(props.tenantId, input());
    emit('sent', result.recipients);
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <form class="flex flex-col gap-4 rounded-lg border border-border p-4 text-sm" data-form="reminder" @submit.prevent="preview ? send() : showPreview()">
    <p class="font-medium">{{ $t('tenant.reminder.title') }}</p>
    <p class="text-text-muted">{{ $t('tenant.reminder.hint') }}</p>

    <fieldset class="flex flex-wrap gap-2">
      <legend class="sr-only">{{ $t('tenant.reminder.topic') }}</legend>
      <label v-for="topic in TOPICS" :key="topic" class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2" :class="form.topic === topic ? 'border-primary bg-primary-soft text-primary' : 'border-border-strong'">
        <input v-model="form.topic" type="radio" name="reminder-topic" :value="topic" class="accent-primary" :data-topic="topic" @change="reset" />
        {{ $t(`tenant.reminder.topics.${topic}`) }}
      </label>
    </fieldset>

    <template v-if="form.topic === 'other'">
      <label class="flex max-w-xl flex-col gap-1.5">
        <span class="font-medium">{{ $t('tenant.reminder.subject') }}</span>
        <input v-model="form.subject" maxlength="150" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reminder-subject" @input="reset" />
      </label>
      <label class="flex flex-col gap-1.5">
        <span class="font-medium">{{ $t('tenant.reminder.message') }}</span>
        <textarea v-model="form.message" maxlength="5000" rows="5" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reminder-message" @input="reset" />
      </label>
    </template>

    <div v-if="preview" class="flex flex-col gap-3 rounded-md border border-note-border bg-note-soft p-3" data-preview="reminder">
      <p v-if="preview.recipients.length === 0" class="text-crit">{{ $t('tenant.reminder.no_recipients') }}</p>
      <p v-else>{{ $t('tenant.reminder.recipients') }} <span data-recipients>{{ preview.recipients.map((recipient) => `${recipient.name} <${recipient.email}>`).join(', ') }}</span></p>
      <p v-if="preview.last_sent_at" class="text-warn" data-last-sent>{{ $t('tenant.reminder.last_sent', { date: dateTime(preview.last_sent_at) }) }}</p>
      <article v-for="letter in preview.letters" :key="letter.locale" class="flex flex-col gap-1 rounded-md border border-border bg-surface p-3" :data-letter="letter.locale">
        <p class="text-xs font-medium uppercase text-text-muted">{{ letter.locale }}</p>
        <p class="font-semibold">{{ letter.subject }}</p>
        <p class="whitespace-pre-line">{{ letter.body }}</p>
      </article>
    </div>

    <ErrorNotice v-if="error" :error="error" />

    <div class="flex flex-wrap gap-2">
      <button v-if="!preview" type="submit" class="rounded-md border border-border-strong px-4 py-2 font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy || !ready" data-action="reminder-preview">{{ $t('tenant.reminder.preview') }}</button>
      <button v-else type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || preview.recipients.length === 0" data-action="reminder-send">{{ $t('tenant.reminder.send') }}</button>
      <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" data-action="reminder-close" @click="emit('close')">{{ $t('common.cancel') }}</button>
    </div>
  </form>
</template>
