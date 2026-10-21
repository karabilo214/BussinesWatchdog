import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import * as textsApi from '@/api/observationTexts';
import ObservationTexts from '@/components/ObservationTexts.vue';
import { localization, setLocale } from '@/i18n';

vi.mock('@/api/observationTexts', () => ({ getObservationTexts: vi.fn() }));

const texts = {
  provider_set: true,
  texts: {
    de: { privacy: 'Funktionsüberwachung des Bestellvorgangs (Business Watchdog)', lia: 'Interessenabwägung' },
    en: { privacy: 'Monitoring of the checkout process (Business Watchdog)', lia: 'Legitimate interest assessment' },
  },
};

async function opened() {
  const wrapper = mount(ObservationTexts, { global: { plugins: [localization] } });
  const details = wrapper.get('[data-observation-texts]');
  (details.element as HTMLDetailsElement).open = true;
  await details.trigger('toggle');
  await flushPromises();

  return wrapper;
}

describe('observation texts', () => {
  it('loads the texts only when opened and switches between German and English', async () => {
    setLocale('ru');
    vi.mocked(textsApi.getObservationTexts).mockClear().mockResolvedValue(texts);
    const closed = mount(ObservationTexts, { global: { plugins: [localization] } });
    await flushPromises();
    expect(textsApi.getObservationTexts).not.toHaveBeenCalled();
    expect(closed.text()).toContain('Тексты для политики конфиденциальности вашего магазина');

    const wrapper = await opened();
    expect((wrapper.get('[data-privacy-text]').element as HTMLTextAreaElement).value).toBe('Funktionsüberwachung des Bestellvorgangs (Business Watchdog)');
    await wrapper.get('[data-language="en"]').trigger('click');
    expect((wrapper.get('[data-privacy-text]').element as HTMLTextAreaElement).value).toBe('Monitoring of the checkout process (Business Watchdog)');
    expect(wrapper.find('[data-provider-missing]').exists()).toBe(false);
    expect(wrapper.find('[data-action="download-lia"]').exists()).toBe(true);
  });

  it('warns about a missing provider and about a failed request', async () => {
    vi.mocked(textsApi.getObservationTexts).mockResolvedValue({ ...texts, provider_set: false });
    expect((await opened()).find('[data-provider-missing]').exists()).toBe(true);
    vi.mocked(textsApi.getObservationTexts).mockRejectedValue(new Error('offline'));
    expect((await opened()).find('[role="alert"]').exists()).toBe(true);
  });
});
