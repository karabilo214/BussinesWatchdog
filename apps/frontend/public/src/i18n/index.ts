import { createLocalization, LOCALES, type Locale } from '@bw/i18n';
import de from './locales/de.json';
import en from './locales/en.json';
import ru from './locales/ru.json';

export const messages = { ru, en, de };

/** The language is part of the URL (/ru/, /en/, /de/), so every prerendered page has a fixed locale. */
export function localeFromPath(path: string): Locale {
  const segment = path.split('/')[1] ?? '';

  return (LOCALES as string[]).includes(segment) ? (segment as Locale) : 'en';
}

export function localizationFor(path: string) {
  return createLocalization({ messages, storageKey: 'bw.public.locale', locale: localeFromPath(path) });
}
