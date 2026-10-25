<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{ open: boolean; title: string }>();
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement | null>(null);

watch(
  () => props.open,
  async (open) => {
    await nextTick();
    const element = dialog.value;
    if (element === null) return;
    if (open && !element.open) {
      if (typeof element.showModal === 'function') element.showModal();
      else element.setAttribute('open', '');
    } else if (!open && element.open) {
      if (typeof element.close === 'function') element.close();
      else element.removeAttribute('open');
    }
  },
  { immediate: true },
);

onBeforeUnmount(() => {
  if (dialog.value?.open && typeof dialog.value.close === 'function') dialog.value.close();
});

function backdrop(event: MouseEvent): void {
  if (event.target === dialog.value) emit('close');
}
</script>

<template>
  <dialog
    ref="dialog"
    class="m-auto max-h-[90vh] w-[min(56rem,calc(100vw-2rem))] overflow-y-auto rounded-xl border border-border bg-surface p-0 text-text shadow-xl backdrop:bg-black/50"
    :aria-label="title"
    @cancel.prevent="emit('close')"
    @click="backdrop"
  >
    <div v-if="open" class="flex flex-col gap-4 p-5 text-sm">
      <div class="flex items-start justify-between gap-3">
        <h2 class="text-lg font-semibold">{{ title }}</h2>
        <button type="button" class="rounded-md border border-border-strong px-3 py-1.5 font-medium hover:bg-surface-muted" data-action="close-detail" @click="emit('close')">{{ $t('store_data.close') }}</button>
      </div>
      <slot />
    </div>
  </dialog>
</template>
