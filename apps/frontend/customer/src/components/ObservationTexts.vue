<script setup lang="ts">
import { computed, ref } from 'vue';
import { getObservationTexts, type ObservationTexts } from '@/api/observationTexts';

type Language = 'de' | 'en';

const texts = ref<ObservationTexts | null>(null);
const failed = ref(false);
const language = ref<Language>('de');
const copied = ref(false);
const current = computed(() => texts.value?.texts[language.value] ?? null);

async function open(event: Event): Promise<void> {
  if (!(event.target as HTMLDetailsElement).open || texts.value !== null) {
    return;
  }

  try {
    texts.value = await getObservationTexts();
  } catch {
    failed.value = true;
  }
}

async function copy(): Promise<void> {
  if (current.value === null) {
    return;
  }

  try {
    await navigator.clipboard.writeText(current.value.privacy);
    copied.value = true;
  } catch {
    copied.value = false;
  }
}

function download(): void {
  if (current.value === null) {
    return;
  }

  const link = document.createElement('a');
  link.href = URL.createObjectURL(new Blob([current.value.lia], { type: 'text/plain;charset=utf-8' }));
  link.download = `business-watchdog-lia-${language.value}.txt`;
  link.click();
  URL.revokeObjectURL(link.href);
}
</script>

<template>
  <details class="rounded-md border border-border text-sm" data-observation-texts @toggle="open">
    <summary class="cursor-pointer px-3 py-2 font-medium">{{ $t('observation_texts.title') }}</summary>
    <div class="flex flex-col gap-3 border-t border-border p-3">
      <p class="text-[13px] text-text-muted">{{ $t('observation_texts.intro') }}</p>
      <p v-if="failed" class="text-warn" role="alert">{{ $t('observation_texts.failed') }}</p>
      <p v-else-if="texts === null" class="text-text-muted" role="status">{{ $t('common.loading') }}</p>
      <template v-else-if="current">
        <div class="flex gap-2" role="group" :aria-label="$t('observation_texts.language')">
          <button
            v-for="code in (['de', 'en'] as const)"
            :key="code"
            type="button"
            class="rounded-md border px-3 py-1 font-medium"
            :class="language === code ? 'border-primary bg-primary-soft text-primary' : 'border-border-strong bg-surface'"
            :aria-pressed="language === code"
            :data-language="code"
            @click="language = code; copied = false"
          >{{ code === 'de' ? 'Deutsch' : 'English' }}</button>
        </div>
        <label :for="`observation-privacy-${language}`" class="font-medium">{{ $t('observation_texts.privacy') }}</label>
        <textarea :id="`observation-privacy-${language}`" :value="current.privacy" readonly rows="10" class="w-full rounded-md border border-border-strong bg-surface-muted px-3 py-2 text-[13px]" data-privacy-text />
        <div class="flex flex-wrap items-center gap-2">
          <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium text-primary hover:bg-primary-soft" data-action="copy-privacy" @click="copy">{{ $t('common.copy') }}</button>
          <button type="button" class="rounded-md border border-border-strong bg-surface px-3 py-1.5 font-medium text-primary hover:bg-primary-soft" data-action="download-lia" @click="download">{{ $t('observation_texts.lia_download') }}</button>
          <span v-if="copied" class="text-[13px] text-text-muted" role="status">{{ $t('common.copied') }}</span>
        </div>
        <p class="text-[13px] text-text-muted">{{ $t('observation_texts.lia_hint') }}</p>
        <p v-if="!texts.provider_set" class="text-[13px] text-warn" data-provider-missing>{{ $t('observation_texts.provider_missing') }}</p>
      </template>
    </div>
  </details>
</template>
