import { translationProblems } from '@bw/i18n/testing';
import { mount } from '@vue/test-utils';
import { createHead } from '@unhead/vue/client';
import { describe, expect, it } from 'vitest';
import { localeFromPath, localizationFor, messages } from '@/i18n';
import HomeView from '@/views/HomeView.vue';

describe('public site', () => {
  it('has complete translations', () => {
    expect(translationProblems({ en: messages.en, ru: messages.ru, de: messages.de })).toEqual([]);
  });

  it('takes the language from the URL', () => {
    expect(localeFromPath('/de/')).toBe('de');
    expect(localeFromPath('/ru')).toBe('ru');
    expect(localeFromPath('/')).toBe('en');
    expect(localeFromPath('/fr/')).toBe('en');
  });

  it('renders the home page in the page language and links to the dashboard', () => {
    const wrapper = mount(HomeView, { global: { plugins: [localizationFor('/ru/'), createHead()] } });

    expect(wrapper.get('h1').text()).toBe('Узнайте, что оплата сломалась, раньше покупателей');
    expect(wrapper.find('a[href="/app/login"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Заказов не создаёт и денег не списывает');
  });
});
