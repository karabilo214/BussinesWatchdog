<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { Locale } from '@/api/types';
import { LOCALES, setLocale } from '@/i18n';

const { locale } = useI18n();
const names: Record<Locale, string> = { ru: 'Русский', en: 'English', de: 'Deutsch' };

function change(event: Event): void {
  setLocale((event.target as HTMLSelectElement).value as Locale);
}
</script>

<template>
  <label class="flex items-center gap-2 text-sm text-text-muted">
    <span class="sr-only">{{ $t('common.language') }}</span>
    <select
      id="locale-switch"
      class="rounded-md border border-border-strong bg-surface px-2 py-1 text-sm text-text"
      :value="locale"
      @change="change"
    >
      <option v-for="code in LOCALES" :key="code" :value="code">{{ names[code] }}</option>
    </select>
  </label>
</template>
