import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@bw/api-client';
import * as api from '@/api/salesExclusions';
import type { SalesCalendar } from '@/api/salesExclusions';
import SalesCalendarSettings from '@/components/SalesCalendarSettings.vue';
import { localization, setLocale } from '@/i18n';

vi.mock('@/api/salesExclusions', async (original) => ({ ...await original<typeof api>(), setSalesCalendar: vi.fn() }));
const configuration = (): SalesCalendar => ({ region: null, local_holidays: [], version: 1, dataset_version: 'de-2026-2027-v1', supported_years: [2026, 2027] });
const render = (canManage = true) => mount(SalesCalendarSettings, { props: { storeId: 's-1', canManage, configuration: configuration(), state: 'not_configured', holidays: [] }, global: { plugins: [localization] } });

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
  vi.mocked(api.setSalesCalendar).mockResolvedValue({ ...configuration(), region: 'DE-BY', version: 2 });
});

describe('German sales holiday calendar', () => {
  it('requires explicit selection and sends only applicable flags at the known version', async () => {
    const wrapper = render();
    expect(api.setSalesCalendar).not.toHaveBeenCalled();
    expect(wrapper.get('[data-action="save-sales-calendar"]').attributes('disabled')).toBeDefined();
    expect(wrapper.get('#sales-calendar-region').findAll('option')).toHaveLength(17);
    await wrapper.get('#sales-calendar-region').setValue('DE-BY');
    await wrapper.get('[data-local-holiday="assumption"]').setValue(true);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(api.setSalesCalendar).toHaveBeenCalledWith('s-1', configuration(), 'DE-BY', ['assumption']);
    expect(wrapper.emitted('changed')).toHaveLength(1);
  });

  it('clears municipal flags when changing to another state and can clear the calendar', async () => {
    const wrapper = render();
    await wrapper.get('#sales-calendar-region').setValue('DE-BY');
    await wrapper.get('[data-local-holiday="assumption"]').setValue(true);
    await wrapper.get('#sales-calendar-region').setValue('DE-SN');
    expect(wrapper.find('[data-local-holiday="assumption"]').exists()).toBe(false);
    await wrapper.get('[data-local-holiday="corpus_christi"]').setValue(true);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(api.setSalesCalendar).toHaveBeenLastCalledWith('s-1', configuration(), 'DE-SN', ['corpus_christi']);
    await wrapper.setProps({ configuration: { ...configuration(), region: 'DE-SN', local_holidays: ['corpus_christi'], version: 2 } });
    await wrapper.get('#sales-calendar-region').setValue('DE-TH');
    expect((wrapper.get('[data-local-holiday="corpus_christi"]').element as HTMLInputElement).checked).toBe(false);
    await wrapper.get('#sales-calendar-region').setValue('');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(api.setSalesCalendar).toHaveBeenLastCalledWith('s-1', expect.objectContaining({ version: 2 }), null, []);
  });

  it('shows conflicts with an explicit reload instead of overwriting a newer setting', async () => {
    vi.mocked(api.setSalesCalendar).mockRejectedValueOnce(new ApiError(409, 'version_conflict'));
    const wrapper = render();
    await wrapper.get('#sales-calendar-region').setValue('DE-BE');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('другой вкладке');
    expect(wrapper.get('[data-action="save-sales-calendar"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-action="reload-sales-calendar"]').trigger('click');
    expect(wrapper.emitted('changed')).toHaveLength(1);
  });

  it('shows unknown years and readonly holidays without mutation controls', async () => {
    const wrapper = render(false);
    await wrapper.setProps({ state: 'out_of_range', configuration: { ...configuration(), region: 'DE-BE' }, holidays: [{ date: '2026-10-03', codes: ['unity'], comparison_weekday: 7 }] });
    expect(wrapper.find('form').exists()).toBe(false);
    expect(wrapper.text()).toContain('нет проверенного календаря');
    expect(wrapper.text()).toContain('День германского единства');
    expect(api.setSalesCalendar).not.toHaveBeenCalled();
  });

  for (const lang of ['ru', 'en', 'de'] as const) {
    it(`translates all states and holiday names in ${lang}`, async () => {
      setLocale(lang);
      const wrapper = render();
      await wrapper.setProps({ holidays: [{ date: '2026-10-03', codes: ['unity', 'repentance'], comparison_weekday: 7 }] });
      expect(wrapper.text()).not.toContain('sales_calendar.');
      expect(wrapper.text()).toContain('2026, 2027');
    });
  }
});
