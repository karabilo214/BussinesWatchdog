<script setup lang="ts">
import { ref } from 'vue';
import ErrorNotice from './ErrorNotice.vue';

const props = defineProps<{ id: string; label: string; confirmLabel: string; warning: string; action: (reason: string) => Promise<unknown>; danger?: boolean }>();
const emit = defineEmits<{ done: [] }>();

const open = ref(false);
const reason = ref('');
const busy = ref(false);
const error = ref<unknown>(null);

async function submit(): Promise<void> {
  busy.value = true;
  error.value = null;

  try {
    await props.action(reason.value.trim());
    open.value = false;
    reason.value = '';
    emit('done');
  } catch (caught) {
    error.value = caught;
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <div class="flex flex-col gap-2">
    <div v-if="!open">
      <button
        type="button"
        class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium"
        :class="danger ? 'text-crit hover:bg-crit-soft' : 'text-primary hover:bg-primary-soft'"
        @click="open = true"
      >
        {{ label }}
      </button>
    </div>
    <form v-else class="flex flex-col gap-2 rounded-md border border-warn-border bg-warn-soft p-3" @submit.prevent="submit">
      <p class="text-sm">{{ warning }}</p>
      <label :for="id" class="text-sm font-medium">{{ $t('reconciliation.reason') }}</label>
      <textarea :id="id" v-model="reason" rows="2" maxlength="1000" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm"></textarea>
      <ErrorNotice v-if="error" :error="error" />
      <div class="flex flex-wrap gap-2">
        <button
          type="submit"
          class="rounded-md px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
          :class="danger ? 'bg-crit-solid' : 'bg-primary hover:bg-primary-hover'"
          :disabled="busy || reason.trim().length < 5"
        >
          {{ confirmLabel }}
        </button>
        <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 text-sm font-medium" @click="open = false">{{ $t('common.cancel') }}</button>
      </div>
    </form>
  </div>
</template>
