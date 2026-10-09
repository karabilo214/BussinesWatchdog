import type { App, InjectionKey } from 'vue';
import { createI18n } from 'vue-i18n';

export type Locale = 'ru' | 'en' | 'de';

export const LOCALES: Locale[] = ['ru', 'en', 'de'];

export const LOCALE_NAMES: Record<Locale, string> = { ru: 'Русский', en: 'English', de: 'Deutsch' };

export const SET_LOCALE: InjectionKey<(locale: Locale) => void> = Symbol('bw.setLocale');

/** Strings every frontend shares (e.g. the language switch). Apps merge their own messages over these. */
const SHARED = {
  ru: { locale: { label: 'Язык' } },
  en: { locale: { label: 'Language' } },
  de: { locale: { label: 'Sprache' } },
};

type Messages = Record<string, unknown>;

function isLocale(value: string | null | undefined): value is Locale {
  return value !== null && value !== undefined && (LOCALES as string[]).includes(value);
}

export interface LocalizationOptions {
  messages: Record<Locale, Messages>;
  storageKey: string;
  /** A locale fixed by the page (e.g. a prerendered /de/ page); wins over storage and the browser. */
  locale?: Locale;
}

export function createLocalization(options: LocalizationOptions) {
  const initial = options.locale ?? readStored(options.storageKey) ?? browserLocale();
  const i18n = createI18n({
    legacy: false,
    locale: initial,
    fallbackLocale: 'en',
    messages: {
      ru: { ...SHARED.ru, ...options.messages.ru },
      en: { ...SHARED.en, ...options.messages.en },
      de: { ...SHARED.de, ...options.messages.de },
    },
    missingWarn: import.meta.env.DEV,
    fallbackWarn: import.meta.env.DEV,
  });

  function setLocale(locale: Locale): void {
    i18n.global.locale.value = locale;

    if (typeof document !== 'undefined') {
      document.documentElement.lang = locale;
    }

    try {
      window.localStorage.setItem(options.storageKey, locale);
    } catch {
      // Not persisted (private mode, server render); the choice still applies to this page.
    }
  }

  return {
    i18n,
    setLocale,
    install(app: App): void {
      app.use(i18n);
      app.provide(SET_LOCALE, setLocale);

      if (typeof document !== 'undefined') {
        document.documentElement.lang = initial;
      }
    },
  };
}

function readStored(key: string): Locale | null {
  try {
    const saved = typeof window === 'undefined' ? null : window.localStorage.getItem(key);

    return isLocale(saved) ? saved : null;
  } catch {
    return null;
  }
}

function browserLocale(): Locale {
  const browser = typeof navigator === 'undefined' ? 'en' : navigator.language.slice(0, 2);

  return isLocale(browser) ? browser : 'en';
}

export { default as LocaleSwitch } from './LocaleSwitch.vue';
