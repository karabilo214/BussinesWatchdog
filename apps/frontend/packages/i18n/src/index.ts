import type { App, InjectionKey } from 'vue';
import { createI18n } from 'vue-i18n';

export type Locale = 'ru' | 'en' | 'de';

export const LOCALES: Locale[] = ['ru', 'en', 'de'];

export const LOCALE_NAMES: Record<Locale, string> = { ru: 'Русский', en: 'English', de: 'Deutsch' };

export const SET_LOCALE: InjectionKey<(locale: Locale) => void> = Symbol('bw.setLocale');

/** Strings every frontend shares (e.g. the language switch). Apps merge their own messages over these. */
const SHARED = {
  ru: {
    forms: {
      required: 'Заполните это поле.',
      email_invalid: 'Введите корректный адрес электронной почты.',
      code_short: 'Введите код из приложения или код восстановления.',
      invalid: 'Проверьте значение поля.',
      fix_errors: 'Проверьте отмеченные поля.',
      show_password: 'Показать пароль',
      hide_password: 'Скрыть пароль',
      fields: { email: 'Электронная почта', password: 'Пароль', code: 'Код' },
    },
    locale: { label: 'Язык' },
  },
  en: {
    forms: {
      required: 'Complete this field.',
      email_invalid: 'Enter a valid email address.',
      code_short: 'Enter your authenticator or recovery code.',
      invalid: 'Check this field.',
      fix_errors: 'Check the highlighted fields.',
      show_password: 'Show password',
      hide_password: 'Hide password',
      fields: { email: 'Email', password: 'Password', code: 'Code' },
    },
    locale: { label: 'Language' },
  },
  de: {
    forms: {
      required: 'Bitte füllen Sie dieses Feld aus.',
      email_invalid: 'Geben Sie eine gültige E-Mail-Adresse ein.',
      code_short: 'Geben Sie Ihren Authenticator- oder Wiederherstellungscode ein.',
      invalid: 'Prüfen Sie dieses Feld.',
      fix_errors: 'Prüfen Sie die markierten Felder.',
      show_password: 'Passwort anzeigen',
      hide_password: 'Passwort verbergen',
      fields: { email: 'E-Mail-Adresse', password: 'Passwort', code: 'Code' },
    },
    locale: { label: 'Sprache' },
  },
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
