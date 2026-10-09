<script setup lang="ts">
import { inject } from 'vue';
import { useI18n } from 'vue-i18n';
import { LOCALE_NAMES, LOCALES, SET_LOCALE, type Locale } from './index';

const props = withDefaults(defineProps<{ id?: string }>(), { id: 'locale-switch' });
const emit = defineEmits<{ change: [locale: Locale] }>();
const { locale } = useI18n();
const setLocale = inject(SET_LOCALE, null);

function change(event: Event): void {
  const next = (event.target as HTMLSelectElement).value as Locale;
  setLocale?.(next);
  emit('change', next);
}
</script>

<template>
  <label :for="props.id" class="flex items-center gap-2 text-sm text-text-muted">
    <span class="sr-only">{{ $t('locale.label') }}</span>
    <select
      :id="props.id"
      class="rounded-md border border-border-strong bg-surface px-2 py-1 text-sm text-text"
      :value="locale"
      @change="change"
    >
      <option v-for="code in LOCALES" :key="code" :value="code">{{ LOCALE_NAMES[code] }}</option>
    </select>
  </label>
</template>
