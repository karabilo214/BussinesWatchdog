<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { StatusBadge } from '@bw/ui';
import { addContactNote, fetchContactRequest, updateContactRequest, type ContactRequest, type ContactStatus } from '@/api';
import ContactReplies from '@/components/ContactReplies.vue';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { CONTACT_TONES } from '@/components/tones';
import { useFormat } from '@/composables/useFormat';
import { useStaff } from '@/composables/useStaff';
import OwnerLayout from '@/layouts/OwnerLayout.vue';

const props = defineProps<{ id: string }>();
const { dateTime } = useFormat();
const { can } = useStaff();
const item = ref<ContactRequest | null>(null);
const loading = ref(true);
const error = ref<unknown>(null);
const saveError = ref<unknown>(null);
const busy = ref(false);
const saved = ref(false);
const form = reactive({ status: 'new' as ContactStatus });
const note = reactive({ body: '', busy: false, error: null as unknown });

const manage = computed(() => can('contacts.manage'));

function accept(request: ContactRequest): void {
  item.value = request;
  form.status = request.status;
}

async function load(): Promise<void> {
  try {
    accept(await fetchContactRequest(props.id));
  } catch (caught) {
    error.value = caught;
  } finally {
    loading.value = false;
  }
}

async function save(): Promise<void> {
  if (item.value === null || busy.value) return;
  busy.value = true;
  saveError.value = null;
  saved.value = false;

  try {
    accept(await updateContactRequest(item.value.id, { status: form.status }));
    saved.value = true;
  } catch (caught) {
    saveError.value = caught;
  } finally {
    busy.value = false;
  }
}

async function addNote(): Promise<void> {
  if (item.value === null || note.body.trim() === '' || note.busy) return;
  note.busy = true;
  note.error = null;

  try {
    accept(await addContactNote(item.value.id, note.body.trim()));
    note.body = '';
  } catch (caught) {
    note.error = caught;
  } finally {
    note.busy = false;
  }
}

onMounted(load);
</script>

<template>
  <OwnerLayout>
    <div class="flex max-w-3xl flex-col gap-6">
      <RouterLink :to="{ name: 'owner-contacts' }" class="self-start text-sm text-primary hover:underline">← {{ $t('section.contacts.title') }}</RouterLink>
      <p v-if="loading" class="text-sm text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <ErrorNotice v-else-if="error" :error="error" />

      <template v-else-if="item">
        <div class="flex flex-col gap-1">
          <h1 class="text-2xl font-semibold">{{ item.name }}</h1>
          <p class="flex flex-wrap items-center gap-2 text-sm text-text-muted">
            <a :href="`mailto:${item.email}`" class="text-primary hover:underline" data-reply>{{ item.email }}</a>
            <template v-if="item.company">· {{ item.company }}</template>
            · {{ dateTime(item.created_at) }} · {{ item.locale.toUpperCase() }}
            <StatusBadge :tone="CONTACT_TONES[item.status]" :label="$t(`contact_status.${item.status}`)" />
          </p>
        </div>

        <section class="rounded-xl border border-border bg-surface p-5 text-sm">
          <p class="whitespace-pre-wrap break-words" data-message>{{ item.message }}</p>
        </section>

        <ContactReplies :item="item" :manage="manage" @updated="accept" />

        <form v-if="manage" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-form="contact" @submit.prevent="save">
          <label class="flex max-w-xs flex-col gap-1.5">
            <span class="font-medium">{{ $t('contacts.status') }}</span>
            <select v-model="form.status" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="status">
              <option v-for="status in (['new', 'in_progress', 'closed'] as const)" :key="status" :value="status">{{ $t(`contact_status.${status}`) }}</option>
            </select>
          </label>
          <p v-if="saved" role="status" class="text-ok" data-saved>{{ $t('contacts.saved') }}</p>
          <ErrorNotice v-if="saveError" :error="saveError" />
          <button type="submit" class="self-start rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy" data-action="save">{{ $t('contacts.save') }}</button>
        </form>

        <section class="flex flex-col gap-3" data-section="notes">
          <h2 class="text-lg font-semibold">{{ $t('contacts.notes.title') }}</h2>
          <form v-if="manage" class="flex flex-col gap-2 rounded-xl border border-border bg-surface p-4 text-sm" data-form="note" @submit.prevent="addNote">
            <label class="flex flex-col gap-1.5">
              <span class="font-medium">{{ $t('contacts.notes.new') }}</span>
              <textarea v-model="note.body" maxlength="2000" rows="3" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="note" />
              <span class="text-text-muted">{{ $t('contacts.note_hint') }}</span>
            </label>
            <ErrorNotice v-if="note.error" :error="note.error" />
            <button type="submit" class="self-start rounded-md border border-border-strong px-4 py-2 font-medium text-primary hover:bg-primary-soft disabled:opacity-60" :disabled="note.busy || note.body.trim() === ''" data-action="add-note">{{ $t('contacts.notes.add') }}</button>
          </form>
          <p v-if="(item.notes ?? []).length === 0" class="text-sm text-text-muted">{{ $t('contacts.notes.none') }}</p>
          <ul v-else class="flex flex-col gap-2 text-sm">
            <li v-for="entry in item.notes" :key="entry.id" class="flex flex-col gap-1 rounded-lg border border-border bg-surface px-4 py-3" :data-note="entry.id">
              <span class="text-text-muted">{{ dateTime(entry.created_at) }} · {{ entry.author ?? '—' }}</span>
              <span class="whitespace-pre-wrap break-words">{{ entry.body }}</span>
            </li>
          </ul>
        </section>
        <p v-if="item.handled_by" class="text-sm text-text-muted">{{ $t('contacts.handled_by', { name: item.handled_by }) }}</p>
        <p class="text-sm text-text-muted">{{ $t('contacts.retention') }}</p>
      </template>
    </div>
  </OwnerLayout>
</template>
