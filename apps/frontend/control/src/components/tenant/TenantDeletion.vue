<script setup lang="ts">
import { computed, reactive, ref } from 'vue';
import { cancelTenantDeletion, requestTenantDeletion, type TenantCard } from '@/api';
import ErrorNotice from '@/components/ErrorNotice.vue';
import { useFormat } from '@/composables/useFormat';

const props = defineProps<{ card: TenantCard; canDelete: boolean }>();
const emit = defineEmits<{ updated: [card: TenantCard] }>();
const { dateTime } = useFormat();

const opened = ref(false);
const form = reactive({ reason: '', code: '' });
const busy = ref(false);
const error = ref<unknown>(null);
const ready = computed(() => form.reason.trim().length >= 3 && form.code.trim().length >= 6);

function open(): void {
  Object.assign(form, { reason: '', code: '' });
  error.value = null;
  opened.value = true;
}

async function submit(): Promise<void> {
  busy.value = true;
  error.value = null;

  try {
    const confirmation = { reason: form.reason.trim(), confirmation_code: form.code.trim() };
    const card = props.card.deletion ? await cancelTenantDeletion(props.card.id, confirmation) : await requestTenantDeletion(props.card.id, confirmation);
    opened.value = false;
    emit('updated', card);
  } catch (caught) {
    error.value = caught;
  } finally {
    form.code = '';
    busy.value = false;
  }
}
</script>

<template>
  <section v-if="card.deletion" class="flex flex-col gap-3 rounded-xl border border-crit-border bg-crit-soft p-5 text-sm" data-panel="tenant-deletion">
    <p class="text-base font-semibold text-crit">{{ $t('tenant.deletion.scheduled', { date: dateTime(card.deletion.purge_after) }) }}</p>
    <p>{{ $t(`tenant.deletion.by.${card.deletion.requested_via}`, { date: dateTime(card.deletion.requested_at) }) }}</p>
    <p v-if="card.deletion.status === 'failed'" class="font-medium text-crit">{{ $t('tenant.deletion.failed') }}</p>
    <p v-else-if="!card.deletion.can_cancel">{{ $t('tenant.deletion.running') }}</p>
    <button v-else-if="canDelete && !opened" type="button" class="self-start rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" data-action="open-cancel-deletion" @click="open">
      {{ $t('tenant.deletion.cancel') }}
    </button>
  </section>

  <button
    v-else-if="canDelete && !opened"
    type="button"
    class="self-start rounded-md border border-crit-border bg-surface px-3 py-1.5 text-sm font-medium text-crit hover:bg-crit-soft"
    data-action="open-delete-tenant"
    @click="open"
  >
    {{ $t('tenant.deletion.open') }}
  </button>

  <form v-if="opened" class="flex flex-col gap-3 rounded-lg border border-border bg-surface p-4 text-sm" data-form="tenant-deletion" @submit.prevent="submit">
    <p class="font-medium">{{ card.deletion ? $t('tenant.deletion.cancel_hint') : $t('tenant.deletion.warning', { name: card.name }) }}</p>
    <label class="flex flex-col gap-1.5">
      <span class="font-medium">{{ $t('tenant.actions.reason') }}</span>
      <textarea v-model="form.reason" maxlength="500" rows="2" class="rounded-md border border-border-strong bg-surface px-3 py-2" data-field="reason" />
    </label>
    <label class="flex max-w-xs flex-col gap-1.5">
      <span class="font-medium">{{ $t('plans.code_confirm') }}</span>
      <input v-model="form.code" autocomplete="one-time-code" maxlength="32" class="rounded-md border border-border-strong bg-surface px-3 py-2 font-mono tracking-wider" data-field="step-up" />
    </label>
    <ErrorNotice v-if="error" :error="error" />
    <div class="flex flex-wrap gap-2">
      <button
        type="submit"
        class="rounded-md px-3 py-1.5 font-semibold disabled:opacity-60"
        :class="card.deletion ? 'bg-primary text-text-inverse hover:bg-primary-hover' : 'bg-crit-solid text-white'"
        :disabled="busy || !ready"
        data-action="confirm-tenant-deletion"
      >
        {{ card.deletion ? $t('tenant.deletion.cancel') : $t('tenant.deletion.submit') }}
      </button>
      <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium" @click="opened = false">{{ $t('common.cancel') }}</button>
    </div>
  </form>
</template>
