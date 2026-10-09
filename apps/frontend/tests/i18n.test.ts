import { describe, expect, it } from 'vitest';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';

type Tree = { [key: string]: string | Tree };

function flatten(tree: Tree, prefix = ''): Record<string, string> {
  return Object.entries(tree).reduce<Record<string, string>>((all, [key, value]) => {
    const path = prefix === '' ? key : `${prefix}.${key}`;

    return typeof value === 'string' ? { ...all, [path]: value } : { ...all, ...flatten(value, path) };
  }, {});
}

const placeholders = (text: string): string[] => [...text.matchAll(/\{(\w+)\}/g)].map((match) => match[1]!).sort();

describe('translations', () => {
  const base = flatten(en as Tree);

  for (const [locale, messages] of Object.entries({ ru, de })) {
    it(`${locale} has exactly the English keys, no empty strings and the same placeholders`, () => {
      const flat = flatten(messages as Tree);

      expect(Object.keys(flat).sort()).toEqual(Object.keys(base).sort());

      for (const [key, text] of Object.entries(flat)) {
        expect(text.trim(), key).not.toBe('');
        expect(placeholders(text), key).toEqual(placeholders(base[key]!));
      }
    });
  }
});
