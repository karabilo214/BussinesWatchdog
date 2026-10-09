<script setup lang="ts">
import { ref } from 'vue';

const props = defineProps<{ id: string; label: string; value: string }>();
const field = ref<HTMLInputElement | null>(null);
const feedback = ref<'copied' | 'selected' | null>(null);

async function copy(): Promise<void> {
  try {
    await navigator.clipboard.writeText(props.value);
    feedback.value = 'copied';
  } catch {
    field.value?.select();
    feedback.value = 'selected';
  }
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <label :for="id" class="text-sm font-medium">{{ label }}</label>
    <div class="flex flex-wrap gap-2">
      <input
        :id="id"
        ref="field"
        :value="value"
        readonly
        spellcheck="false"
        class="min-w-0 flex-1 rounded-md border border-border-strong bg-surface-muted px-3 py-2 font-mono text-sm"
        @focus="($event.target as HTMLInputElement).select()"
      />
      <button
        type="button"
        class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm font-medium text-primary hover:bg-primary-soft"
        @click="copy"
      >
        {{ $t('common.copy') }}
      </button>
    </div>
    <p v-if="feedback" class="text-[13px] text-text-muted" role="status">{{ $t(`common.${feedback}`) }}</p>
  </div>
</template>
