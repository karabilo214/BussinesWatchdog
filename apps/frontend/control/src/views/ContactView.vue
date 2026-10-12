<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { StatusBadge } from '@bw/ui';
import { fetchContactRequest, updateContactRequest, type ContactRequest, type ContactStatus } from '@/api';
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
const form = reactive({ status: 'new' as ContactStatus, note: '' });

const manage = computed(() => can('contacts.manage'));

function accept(request: ContactRequest): void {
  item.value = request;
  form.status = request.status;
  form.note = request.note ?? '';
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
    accept(await updateContactRequest(item.value.id, { status: form.status, note: form.note.trim() === '' ? null : form.note.trim() }));
    saved.value = true;
  } catch (caught) {
    saveError.value = caught;
  } finally {
    busy.value = false;
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

        <form v-if="manage" class="flex flex-col gap-4 rounded-xl border border-border bg-surface p-5 text-sm" data-form="contact" @submit.prevent="save">
          <label class="flex max-w-xs flex-col gap-1.5">
            <span class="font-medium">{{ $t('contacts.status') }}</span>
            <select v-model="form.status" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="status">
              <option v-for="status in (['new', 'in_progress', 'closed'] as const)" :key="status" :value="status">{{ $t(`contact_status.${status}`) }}</option>
            </select>
          </label>
          <label class="flex flex-col gap-1.5">
            <span class="font-medium">{{ $t('contacts.note') }}</span>
            <textarea v-model="form.note" maxlength="2000" rows="3" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="note" />
            <span class="text-text-muted">{{ $t('contacts.note_hint') }}</span>
          </label>
          <p v-if="saved" role="status" class="text-ok" data-saved>{{ $t('contacts.saved') }}</p>
          <ErrorNotice v-if="saveError" :error="saveError" />
          <button type="submit" class="self-start rounded-md bg-primary px-4 py-2 font-semibold text-text-inverse hover:bg-primary-hover disabled:opacity-60" :disabled="busy" data-action="save">{{ $t('contacts.save') }}</button>
        </form>
        <p v-else-if="item.note" class="text-sm text-text-muted">{{ $t('contacts.note') }}: {{ item.note }}</p>
        <p v-if="item.handled_by" class="text-sm text-text-muted">{{ $t('contacts.handled_by', { name: item.handled_by }) }}</p>
        <p class="text-sm text-text-muted">{{ $t('contacts.retention') }}</p>
      </template>
    </div>
  </OwnerLayout>
</template>
