import { translationProblems } from '@bw/i18n/testing';
import { describe, expect, it } from 'vitest';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';

describe('translations', () => {
  it('ru, en and de have the same keys, no empty texts and the same placeholders', () => {
    expect(translationProblems({ en, ru, de })).toEqual([]);
  });
});
