import { createI18n } from 'vue-i18n';
import type { Locale } from '@/api/types';
import de from './locales/de.json';
import en from './locales/en.json';
import ru from './locales/ru.json';

export const LOCALES: Locale[] = ['ru', 'en', 'de'];

const STORAGE_KEY = 'bw.locale';

function initialLocale(): Locale {
  try {
    const saved = window.localStorage.getItem(STORAGE_KEY);

    if (saved !== null && (LOCALES as string[]).includes(saved)) {
      return saved as Locale;
    }
  } catch {
    // Storage can be unavailable (private mode); fall back to the browser language.
  }

  const browser = navigator.language.slice(0, 2);

  return (LOCALES as string[]).includes(browser) ? (browser as Locale) : 'en';
}

export const i18n = createI18n({
  legacy: false,
  locale: initialLocale(),
  fallbackLocale: 'en',
  messages: { ru, en, de },
  missingWarn: import.meta.env.DEV,
  fallbackWarn: import.meta.env.DEV,
});

export function setLocale(locale: Locale): void {
  i18n.global.locale.value = locale;
  document.documentElement.lang = locale;

  try {
    window.localStorage.setItem(STORAGE_KEY, locale);
  } catch {
    // Not persisted; the choice still applies to this tab.
  }
}
