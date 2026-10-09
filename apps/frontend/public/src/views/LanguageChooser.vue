<script setup lang="ts">
import { LOCALE_NAMES, LOCALES, type Locale } from '@bw/i18n';
import { useHead } from '@unhead/vue';
import { onMounted } from 'vue';

useHead({ title: 'Business Watchdog', htmlAttrs: { lang: 'en' } });

onMounted(() => {
  let preferred: string | null = null;

  try {
    preferred = window.localStorage.getItem('bw.public.locale');
  } catch {
    preferred = null;
  }

  const browser = navigator.language.slice(0, 2);
  const target = [preferred, browser].find((value): value is Locale => value !== null && (LOCALES as string[]).includes(value)) ?? 'en';
  window.location.replace(`/${target}/`);
});
</script>

<template>
  <main class="mx-auto flex min-h-full max-w-md flex-col justify-center gap-6 px-4 py-16">
    <span class="text-lg font-semibold text-brand-800">Business Watchdog</span>
    <h1 class="text-2xl font-semibold">Выберите язык · Choose your language · Sprache wählen</h1>
    <ul class="flex flex-col gap-2">
      <li v-for="code in LOCALES" :key="code">
        <a :href="`/${code}/`" :hreflang="code" :lang="code" class="block rounded-lg border border-border bg-surface px-4 py-3 font-medium text-link hover:bg-primary-soft">{{ LOCALE_NAMES[code] }}</a>
      </li>
    </ul>
  </main>
</template>
