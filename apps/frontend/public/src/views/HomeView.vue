<script setup lang="ts">
import { LOCALE_NAMES, LOCALES, LocaleSwitch, type Locale } from '@bw/i18n';
import { useHead } from '@unhead/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t, locale } = useI18n();
const siteOrigin = (import.meta.env.VITE_SITE_ORIGIN as string | undefined)?.replace(/\/$/, '') ?? '';

useHead(computed(() => ({
  title: t('meta.title'),
  htmlAttrs: { lang: locale.value },
  meta: [{ name: 'description', content: t('meta.description') }],
  link: siteOrigin === ''
    ? []
    : [
        { rel: 'canonical', href: `${siteOrigin}/${locale.value}/` },
        ...LOCALES.map((code) => ({ rel: 'alternate', hreflang: code, href: `${siteOrigin}/${code}/` })),
        { rel: 'alternate', hreflang: 'x-default', href: `${siteOrigin}/` },
      ],
})));

const features = ['checkout', 'attempts', 'money', 'data'] as const;

function switchTo(next: Locale): void {
  window.location.assign(`/${next}/`);
}
</script>

<template>
  <div class="flex min-h-full flex-col">
    <header class="border-b border-border bg-surface">
      <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-4 px-4 py-4 sm:px-6">
        <span class="text-base font-semibold text-brand-800">Business Watchdog</span>
        <div class="flex items-center gap-3">
          <LocaleSwitch id="public-locale" @change="switchTo" />
          <a href="/app/login" class="rounded-md bg-primary px-4 py-2 text-sm font-semibold text-text-inverse hover:bg-primary-hover">{{ t('nav.sign_in') }}</a>
        </div>
      </div>
    </header>

    <main class="flex-1">
      <section class="mx-auto flex max-w-5xl flex-col gap-5 px-4 py-16 sm:px-6 sm:py-24">
        <span class="text-xs font-semibold uppercase tracking-wider text-link">{{ t('hero.eyebrow') }}</span>
        <h1 class="max-w-3xl text-3xl font-semibold leading-tight sm:text-5xl">{{ t('hero.title') }}</h1>
        <p class="max-w-2xl text-lg text-text-muted">{{ t('hero.lead') }}</p>
        <div class="flex flex-wrap gap-3 pt-2">
          <a href="/app/login" class="rounded-md bg-primary px-5 py-3 text-sm font-semibold text-text-inverse hover:bg-primary-hover">{{ t('nav.sign_in') }}</a>
          <a href="#how" class="rounded-md border border-border-strong bg-surface px-5 py-3 text-sm font-semibold text-primary hover:bg-primary-soft">{{ t('nav.how') }}</a>
        </div>
      </section>

      <section id="how" class="border-y border-border bg-surface">
        <div class="mx-auto flex max-w-5xl flex-col gap-8 px-4 py-16 sm:px-6">
          <h2 class="text-2xl font-semibold">{{ t('features.title') }}</h2>
          <div class="grid gap-6 sm:grid-cols-2">
            <article v-for="feature in features" :key="feature" class="flex flex-col gap-2 border-l-2 border-brand-200 pl-4">
              <h3 class="font-semibold">{{ t(`features.${feature}_title`) }}</h3>
              <p class="text-sm text-text-muted">{{ t(`features.${feature}_body`) }}</p>
            </article>
          </div>
        </div>
      </section>

      <section class="mx-auto flex max-w-5xl flex-col gap-3 px-4 py-16 sm:px-6">
        <h2 class="text-2xl font-semibold">{{ t('limits.title') }}</h2>
        <p class="max-w-3xl text-text-muted">{{ t('limits.body') }}</p>
      </section>
    </main>

    <footer class="border-t border-border">
      <nav :aria-label="t('footer.languages')" class="mx-auto flex max-w-5xl flex-wrap gap-4 px-4 py-6 text-sm sm:px-6">
        <a v-for="code in LOCALES" :key="code" :href="`/${code}/`" :hreflang="code" :lang="code" class="text-link hover:underline">{{ LOCALE_NAMES[code] }}</a>
      </nav>
    </footer>
  </div>
</template>
