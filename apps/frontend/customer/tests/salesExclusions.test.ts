import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@bw/api-client';
import * as api from '@/api/salesExclusions';
import type { SalesExclusions } from '@/api/salesExclusions';
import SalesExclusionSettings from '@/components/SalesExclusionSettings.vue';
import { localization, setLocale } from '@/i18n';

vi.mock('@/api/salesExclusions', () => ({ getSalesExclusions: vi.fn(), setSalesExclusion: vi.fn(), setSalesCalendar: vi.fn(), SALES_CALENDAR_REGIONS: ['DE-BE', 'DE-BY', 'DE-SN', 'DE-TH'] }));
const data = (mode: 'live' | 'test' = 'live'): SalesExclusions => ({
  timezone: 'Europe/Berlin', min_date: '2026-10-02', max_date: '2026-10-30', mode,
  calendar: { region: null, local_holidays: [], version: 1, dataset_version: 'de-2026-2027-v1', supported_years: [2026, 2027] }, holidays: [], calendar_state: 'not_configured',
  entries: [
    { date: '2026-10-29', kind: 'promotion', timezone: 'Europe/Berlin', from: '2026-10-28T23:00:00Z', to: '2026-10-29T23:00:00Z', editable: true },
    { date: '2026-10-28', kind: 'pause', timezone: 'Europe/Berlin', from: '2026-10-27T23:00:00Z', to: '2026-10-28T23:00:00Z', editable: false },
  ],
});
const render = (canManage = true) => mount(SalesExclusionSettings, { props: { storeId: 's-1', canManage }, global: { plugins: [localization] } });

beforeEach(() => {
  vi.resetAllMocks();
  setLocale('ru');
  vi.mocked(api.getSalesExclusions).mockResolvedValue(data());
  vi.mocked(api.setSalesExclusion).mockResolvedValue(data());
});

describe('sales day annotations', () => {
  it('adds and removes one annotation without exposing removal of automatic pauses', async () => {
    const wrapper = render();
    await flushPromises();
    expect(wrapper.get('#sales-exclusions-date').attributes('min')).toBe('2026-10-02');
    expect(wrapper.findAll('[data-action="remove-sales-exclusion"]')).toHaveLength(1);
    await wrapper.get('#sales-exclusions-date').setValue('2026-10-25');
    await wrapper.get('#sales-exclusions-kind').setValue('holiday');
    await wrapper.get('form[data-sales-annotation-form]').trigger('submit');
    await flushPromises();
    expect(api.setSalesExclusion).toHaveBeenLastCalledWith('s-1', { date: '2026-10-25', kind: 'holiday', mode: 'live', excluded: true });
    await wrapper.get('[data-action="remove-sales-exclusion"]').trigger('click');
    await flushPromises();
    expect(api.setSalesExclusion).toHaveBeenLastCalledWith('s-1', { date: '2026-10-29', kind: 'promotion', mode: 'live', excluded: false });
    expect(wrapper.text()).toContain('не включают тревоги');
  });

  it('gives a reader no mutation controls', async () => {
    const wrapper = render(false);
    await flushPromises();
    expect(wrapper.find('form[data-sales-annotation-form]').exists()).toBe(false);
    expect(wrapper.find('[data-action="remove-sales-exclusion"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Пауза наблюдения');
    expect(api.setSalesExclusion).not.toHaveBeenCalled();
  });

  it('keeps the saved list when validation fails and explains the range', async () => {
    vi.mocked(api.setSalesExclusion).mockRejectedValue(new ApiError(422, 'validation_failed', { date: ['sales_exclusion_range_invalid'] }));
    const wrapper = render();
    await flushPromises();
    await wrapper.get('form[data-sales-annotation-form]').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('28 дней');
    expect(wrapper.text()).toContain('2026-10-29');
    expect(wrapper.get('#sales-exclusions-date').attributes('aria-invalid')).toBe('true');
  });

  it('ignores an earlier mode response and clears the old list during loading', async () => {
    let finish!: (value: SalesExclusions) => void;
    vi.mocked(api.getSalesExclusions).mockImplementationOnce(() => new Promise((resolve) => { finish = resolve; })).mockResolvedValueOnce({ ...data('test'), entries: [] });
    const wrapper = render();
    await wrapper.get('#sales-exclusions-mode').setValue('test');
    await flushPromises();
    expect(api.getSalesExclusions).toHaveBeenLastCalledWith('s-1', 'test');
    finish(data());
    await flushPromises();
    expect(wrapper.text()).not.toContain('2026-10-29');
    expect(wrapper.text()).toContain('сохранённых пометок нет');
  });

  it('clears the previous store while the next store loads', async () => {
    const wrapper = render();
    await flushPromises();
    vi.mocked(api.getSalesExclusions).mockReturnValueOnce(new Promise(() => {}));
    await wrapper.setProps({ storeId: 's-2' });
    expect(wrapper.text()).not.toContain('2026-10-29');
    expect(wrapper.find('form[data-sales-annotation-form]').exists()).toBe(false);
  });

  for (const locale of ['ru', 'en', 'de'] as const) {
    it(`renders translated labels in ${locale}`, async () => {
      setLocale(locale);
      const wrapper = render();
      await flushPromises();
      expect(wrapper.text()).not.toContain('sales_exclusions.');
      expect(wrapper.get('#sales-exclusions-kind').findAll('option')).toHaveLength(2);
    });
  }
});
