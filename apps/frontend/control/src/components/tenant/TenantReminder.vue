<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { previewTenantReminder, saveReminderTemplate, sendTenantReminder, type ReminderEditor, type ReminderLetter, type ReminderLocale, type ReminderTopic } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';

const TOPICS: ReminderTopic[] = ['subscription', 'payment', 'other'];
const LOCALES: ReminderLocale[] = ['ru', 'en', 'de'];

const props = defineProps<{ tenantId: string }>();
const emit = defineEmits<{ close: []; sent: [recipients: number] }>();

const { dateTime } = useFormat();
const topic = ref<ReminderTopic | null>(null);
const editor = ref<ReminderEditor | null>(null);
const chosen = ref<string[]>([]);
const letters = reactive<Record<ReminderLocale, ReminderLetter>>({ ru: { subject: '', body: '' }, en: { subject: '', body: '' }, de: { subject: '', body: '' } });
const rendered = ref<ReminderEditor['letters'] | null>(null);
const busy = ref(false);
const error = ref<unknown>(null);
const saved = ref<ReminderLocale | null>(null);

const locales = computed<ReminderLocale[]>(() => {
  const members = editor.value?.members ?? [];

  return LOCALES.filter((locale) => members.some((member) => chosen.value.includes(member.user_id) && member.locale === locale));
});
const valid = (letter: ReminderLetter) => letter.subject.trim().length >= 3 && letter.subject.trim().length <= 150 && letter.body.trim().length >= 10 && letter.body.trim().length <= 10000;
const ready = computed(() => chosen.value.length > 0 && locales.value.length > 0 && locales.value.every((locale) => valid(letters[locale])));

async function task<T>(work: () => Promise<T>): Promise<T | undefined> {
  busy.value = true;
  error.value = null;

  try {
    return await work();
  } catch (caught) {
    error.value = caught;

    return undefined;
  } finally {
    busy.value = false;
  }
}

async function open(next: ReminderTopic): Promise<void> {
  topic.value = next;
  rendered.value = null;
  saved.value = null;
  const loaded = await task(() => previewTenantReminder(props.tenantId, { topic: next }));
  if (!loaded) return;
  editor.value = loaded;
  chosen.value = [...loaded.suggested_recipient_ids];
  for (const locale of LOCALES) letters[locale] = { subject: loaded.templates[locale].subject, body: loaded.templates[locale].body };
}

function draft(): { topic: ReminderTopic; recipient_ids: string[]; letters: Partial<Record<ReminderLocale, ReminderLetter>> } {
  return { topic: topic.value as ReminderTopic, recipient_ids: [...chosen.value], letters: Object.fromEntries(locales.value.map((locale) => [locale, { subject: letters[locale].subject.trim(), body: letters[locale].body.trim() }])) };
}

async function showRendered(): Promise<void> {
  const result = await task(() => previewTenantReminder(props.tenantId, draft()));
  if (result) rendered.value = result.letters;
}

async function saveTemplate(locale: ReminderLocale): Promise<void> {
  if (!topic.value || !valid(letters[locale])) return;
  const result = await task(() => saveReminderTemplate(topic.value as ReminderTopic, locale, { subject: letters[locale].subject.trim(), body: letters[locale].body.trim() }));
  if (result && editor.value) {
    editor.value.templates[locale] = result;
    saved.value = locale;
  }
}

async function send(): Promise<void> {
  if (!ready.value || busy.value) return;
  const result = await task(() => sendTenantReminder(props.tenantId, draft()));
  if (result) emit('sent', result.messages.length);
}
</script>

<template>
  <form class="flex flex-col gap-4 rounded-lg border border-border p-4 text-sm" data-form="reminder" @submit.prevent="send">
    <p class="font-medium">{{ $t('tenant.reminder.title') }}</p>
    <p class="text-text-muted">{{ $t('tenant.reminder.hint') }}</p>

    <fieldset class="flex flex-wrap gap-2">
      <legend class="sr-only">{{ $t('tenant.reminder.topic') }}</legend>
      <label v-for="item in TOPICS" :key="item" class="flex cursor-pointer items-center gap-2 rounded-md border px-3 py-2" :class="topic === item ? 'border-primary bg-primary-soft text-primary' : 'border-border-strong'">
        <input type="radio" name="reminder-topic" :value="item" :checked="topic === item" class="accent-primary" :data-topic="item" @change="open(item)" />
        {{ $t(`tenant.reminder.topics.${item}`) }}
      </label>
    </fieldset>

    <template v-if="editor && topic">
      <p v-if="editor.last_sent_at" class="text-warn" data-last-sent>{{ $t('tenant.reminder.last_sent', { date: dateTime(editor.last_sent_at) }) }}</p>

      <fieldset class="flex flex-col gap-2" data-recipients>
        <legend class="font-medium">{{ $t('tenant.reminder.recipients') }}</legend>
        <p v-if="editor.members.length === 0" class="text-crit">{{ $t('tenant.reminder.no_recipients') }}</p>
        <label v-for="member in editor.members" :key="member.user_id" class="flex items-center gap-2" :class="!member.active && 'opacity-60'">
          <input v-model="chosen" type="checkbox" :value="member.user_id" :disabled="!member.active" :data-recipient="member.email" @change="rendered = null" />
          <span>{{ member.name }} &lt;{{ member.email }}&gt; · {{ $t(`member_role.${member.role}`) }} · {{ member.locale.toUpperCase() }}</span>
          <span v-if="!member.active" class="text-text-muted">({{ $t('tenant.reminder.inactive') }})</span>
        </label>
      </fieldset>

      <p class="text-text-muted">{{ $t('tenant.reminder.placeholders', { list: editor.placeholders.join(', ') }) }}</p>

      <section v-for="locale in locales" :key="locale" class="flex flex-col gap-2 rounded-md border border-border bg-surface p-3" :data-letter-editor="locale">
        <p class="text-xs font-medium uppercase text-text-muted">{{ locale }} <span v-if="editor.templates[locale].customized" class="normal-case">· {{ $t('tenant.reminder.customized') }}</span></p>
        <label class="flex flex-col gap-1">
          <span class="font-medium">{{ $t('tenant.reminder.subject') }}</span>
          <input v-model="letters[locale].subject" maxlength="150" class="rounded-md border border-border-strong bg-surface px-3 py-2" :data-field="`subject-${locale}`" @input="rendered = null; saved = null" />
        </label>
        <label class="flex flex-col gap-1">
          <span class="font-medium">{{ $t('tenant.reminder.message') }}</span>
          <textarea v-model="letters[locale].body" maxlength="10000" rows="8" class="rounded-md border border-border-strong bg-surface px-3 py-2" :data-field="`body-${locale}`" @input="rendered = null; saved = null" />
        </label>
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy || !valid(letters[locale])" :data-action="`save-template-${locale}`" @click="saveTemplate(locale)">{{ $t('tenant.reminder.save_template') }}</button>
          <span v-if="saved === locale" class="text-ok" role="status" data-template-saved>{{ $t('tenant.reminder.template_saved') }}</span>
        </div>
      </section>

      <div v-if="rendered" class="flex flex-col gap-3 rounded-md border border-note-border bg-note-soft p-3" data-preview="reminder">
        <article v-for="letter in rendered" :key="letter.locale" class="flex flex-col gap-1 rounded-md border border-border bg-surface p-3" :data-letter="letter.locale">
          <p class="text-xs font-medium uppercase text-text-muted">{{ letter.locale }}</p>
          <p class="font-semibold">{{ letter.subject }}</p>
          <p class="whitespace-pre-line">{{ letter.body }}</p>
        </article>
      </div>
    </template>

    <ErrorNotice v-if="error" :error="error" />

    <div class="flex flex-wrap gap-2">
      <button v-if="editor" type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy || !ready" data-action="reminder-preview" @click="showRendered">{{ $t('tenant.reminder.preview') }}</button>
      <button v-if="editor" type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !ready" data-action="reminder-send">{{ $t('tenant.reminder.send') }}</button>
      <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" data-action="reminder-close" @click="emit('close')">{{ $t('common.cancel') }}</button>
    </div>
  </form>
</template>
