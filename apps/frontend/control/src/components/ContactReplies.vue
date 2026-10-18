<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { replyToContactRequest, saveContactReplyTemplate, type ContactReplyTemplateCode, type ContactRequest } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';

const props = defineProps<{ item: ContactRequest; manage: boolean }>();
const emit = defineEmits<{ updated: [item: ContactRequest] }>();
const { dateTime } = useFormat();

const open = ref(false);
const busy = ref(false);
const error = ref<unknown>(null);
const sent = ref(false);
const form = reactive({ template: 'answer' as ContactReplyTemplateCode, subject: '', body: '' });
const ready = computed(() => form.subject.trim() !== '' && form.body.trim().length >= 2);
const templateSaved = ref(false);

async function saveTemplate(): Promise<void> {
  if (form.subject.trim().length < 3 || form.body.trim() === '' || busy.value) return;
  busy.value = true;
  error.value = null;

  try {
    const saved = await saveContactReplyTemplate(form.template, props.item.locale, { subject: form.subject.trim(), body: form.body.trim() });
    const template = props.item.reply_templates?.find((candidate) => candidate.code === form.template);
    if (template) Object.assign(template, saved);
    templateSaved.value = true;
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}

function useTemplate(code: ContactReplyTemplateCode): void {
  const template = props.item.reply_templates?.find((candidate) => candidate.code === code);
  form.template = code;
  form.subject = template?.subject ?? '';
  form.body = template?.body ?? '';
  templateSaved.value = false;
}

function start(): void {
  useTemplate('answer');
  sent.value = false;
  error.value = null;
  open.value = true;
}

async function send(): Promise<void> {
  if (!ready.value || busy.value) return;
  busy.value = true;
  error.value = null;

  try {
    emit('updated', await replyToContactRequest(props.item.id, { template: form.template, subject: form.subject.trim(), body: form.body.trim() }));
    open.value = false;
    sent.value = true;
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <section class="flex flex-col gap-3" data-section="replies">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="text-lg font-semibold">{{ $t('contacts.replies.title') }}</h2>
      <button v-if="manage && !open" type="button" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover" data-action="reply" @click="start">{{ $t('contacts.replies.reply') }}</button>
    </div>
    <p v-if="sent" role="status" class="rounded-md border border-ok-border bg-ok-soft px-3 py-2 text-sm text-ok" data-sent>{{ $t('contacts.replies.sent', { email: item.email }) }}</p>

    <form v-if="open" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-form="reply" @submit.prevent="send">
      <label class="flex max-w-xs flex-col gap-1.5">
        <span class="font-medium">{{ $t('contacts.replies.template') }}</span>
        <select :value="form.template" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reply-template" @change="useTemplate(($event.target as HTMLSelectElement).value as ContactReplyTemplateCode)">
          <option v-for="template in item.reply_templates ?? []" :key="template.code" :value="template.code">{{ $t(`contacts.replies.templates.${template.code}`) }}{{ template.customized ? ` · ${$t('contacts.replies.customized')}` : '' }}</option>
        </select>
      </label>
      <label class="flex flex-col gap-1.5">
        <span class="font-medium">{{ $t('contacts.replies.subject') }}</span>
        <input v-model="form.subject" maxlength="200" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reply-subject" />
      </label>
      <label class="flex flex-col gap-1.5">
        <span class="font-medium">{{ $t('contacts.replies.body') }}</span>
        <textarea v-model="form.body" maxlength="10000" rows="8" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reply-body" />
        <span class="text-text-muted">{{ $t('contacts.replies.frame', { name: item.name, locale: item.locale.toUpperCase() }) }}</span>
      </label>
      <ErrorNotice v-if="error" :error="error" />
      <div class="flex flex-wrap gap-2">
        <button type="submit" class="rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy || !ready" data-action="send-reply">{{ $t('contacts.replies.send', { email: item.email }) }}</button>
        <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="busy || form.subject.trim().length < 3 || form.body.trim() === ''" data-action="save-reply-template" @click="saveTemplate">{{ $t('contacts.replies.save_template', { locale: item.locale.toUpperCase() }) }}</button>
        <button type="button" class="rounded-md border border-border-strong px-4 py-2 font-medium hover:bg-surface-muted" @click="open = false">{{ $t('common.cancel') }}</button>
        <span v-if="templateSaved" class="self-center text-ok" role="status" data-template-saved>{{ $t('contacts.replies.template_saved') }}</span>
      </div>
    </form>

    <p v-if="(item.replies ?? []).length === 0" class="text-sm text-text-muted">{{ $t('contacts.replies.none') }}</p>
    <article v-for="reply in item.replies ?? []" :key="reply.id" class="flex flex-col gap-1 rounded-xl border border-border bg-surface p-4 text-sm" :data-reply-item="reply.id">
      <p class="text-text-muted">{{ dateTime(reply.sent_at) }}<template v-if="reply.sent_by"> · {{ reply.sent_by }}</template> · {{ $t(`contacts.replies.templates.${reply.template}`) }}</p>
      <p class="font-semibold">{{ reply.subject }}</p>
      <p class="whitespace-pre-wrap break-words">{{ reply.body }}</p>
    </article>
  </section>
</template>
