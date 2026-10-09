import { translationProblems } from '@bw/i18n/testing';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import App from '@/App.vue';
import { localization } from '@/i18n';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';
import { buildRouter } from '@/router';

describe('control app', () => {
  it('has complete translations', () => {
    expect(translationProblems({ en, ru, de })).toEqual([]);
  });

  it('routes /owner and /admin to their sections', async () => {
    const router = buildRouter();
    localization.setLocale('en');

    for (const [path, heading] of [['/owner', 'Service owner panel'], ['/admin', 'Support panel']] as const) {
      await router.push(path);
      const wrapper = mount(App, { global: { plugins: [localization, router] } });
      expect(wrapper.get('h1').text()).toBe(heading);
    }
  });
});
