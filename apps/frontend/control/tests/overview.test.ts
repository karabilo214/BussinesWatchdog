import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api';
import type { BusinessOverview, StaffMe } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';

vi.mock('@/api', () => ({
  fetchMe: vi.fn(),
  login: vi.fn(),
  submitMfa: vi.fn(),
  logout: vi.fn(),
  fetchOverview: vi.fn(),
  fetchPluginFunnel: vi.fn(),
  fetchSubscriptions: vi.fn(),
}));

const mocked = vi.mocked(api);

function staff(capabilities: string[]): StaffMe {
  return { staff_id: 's-1', name: 'Founder', first_name: null, last_name: 'Founder', photo_url: null, email: 'f@example.test', locale: 'ru', role: 'platform_owner', mfa_enabled: true, capabilities, billing_mode: 'test', session: { idle_minutes: 30, absolute_hours: 8 } };
}

const OVERVIEW: BusinessOverview = {
  mode: 'live',
  from: '2026-09-21T00:00:00.000000Z',
  to: '2026-10-21T00:00:00.000000Z',
  previous: { from: '2026-08-22T00:00:00.000000Z', to: '2026-09-21T00:00:00.000000Z' },
  as_of: '2026-10-20T10:00:00Z',
  last_synced_at: '2026-10-20T09:00:00Z',
  customers: { paying: 3, trial: 1, past_due: 1, cancel_scheduled: 1 },
  mrr: [
    { currency: 'EUR', currency_exponent: 2, numerator_minor: '13450', denominator: '3', contracts: 2, discounted_contracts: 1, unpriced_contracts: 0 },
    { currency: 'USD', currency_exponent: 2, numerator_minor: '2100', denominator: '1', contracts: 1, discounted_contracts: 0, unpriced_contracts: 0 },
  ],
  money: [{ currency: 'EUR', currency_exponent: 2, collected_minor: '21900', collected_tax_minor: '3497', collections: 2, refunded_minor: '29000', refunds: 1, net_minor: '-7100' }],
  money_previous: [{ currency: 'EUR', currency_exponent: 2, collected_minor: '1900', collected_tax_minor: '300', collections: 1, refunded_minor: '0', refunds: 0, net_minor: '1900' }],
  churn: { ended: 1, at_start: 6 },
  churn_previous: { ended: 0, at_start: 0 },
};

async function open(capabilities: string[]) {
  mocked.fetchMe.mockResolvedValue(staff(capabilities));
  await useStaff().load(true);
  const router = buildRouter();
  await router.push('/owner');
  await router.isReady();
  const wrapper = mount(App, { global: { plugins: [localization, router] } });
  await flushPromises();
  await vi.waitFor(() => expect(wrapper.find('h1').exists()).toBe(true));
  await flushPromises();

  return { wrapper, router };
}

const FUNNEL: api.PluginFunnel = {
  from: '2026-09-21T00:00:00.000000Z',
  to: '2026-10-21T00:00:00.000000Z',
  stages: [
    { stage: 'click', attributed: 40, total: 40, conversion_percent: null },
    { stage: 'signup_view', attributed: 30, total: 30, conversion_percent: 75 },
    { stage: 'signup', attributed: 10, total: 10, conversion_percent: 33.3 },
    { stage: 'code', attributed: 6, total: 9, conversion_percent: 60 },
    { stage: 'connected', attributed: 5, total: 8, conversion_percent: 83.3 },
  ],
  placements: [{ placement: 'plugins_card', click: 30, signup_view: 25, signup: 8, code: 5, connected: 4 }],
};

beforeEach(() => {
  vi.clearAllMocks();
  mocked.fetchPluginFunnel.mockResolvedValue(FUNNEL);
  useStaff().forget();
  localization.setLocale('ru');
});

describe('overview', () => {
  it('shows customers, MRR per currency from an exact fraction, money with a negative net and churn with counts', async () => {
    mocked.fetchOverview.mockResolvedValue(OVERVIEW);
    const { wrapper } = await open(['billing.read']);

    expect(mocked.fetchOverview).toHaveBeenCalledWith({ mode: 'live' });
    expect(wrapper.get('[data-kpi="paying"]').text()).toContain('3');
    expect(wrapper.get('[data-kpi="past_due"]').attributes('href')).toBe('/owner/subscriptions?status=past_due');
    expect(wrapper.get('[data-mrr="EUR"]').text()).toMatch(/≈ 44,83/);
    expect(wrapper.get('[data-mrr="EUR"]').text()).toContain('со скидкой: 1');
    expect(wrapper.get('[data-mrr="USD"]').text()).not.toContain('≈');
    expect(wrapper.get('[data-mrr="USD"]').text()).toMatch(/21,00/);
    expect(wrapper.get('[data-money="EUR"]').text()).toMatch(/-71,00/);
    expect(wrapper.get('[data-money="EUR"]').text()).toMatch(/19,00/);
    expect(wrapper.get('[data-kpi="churn"]').text()).toContain('1 из 6');
    expect((wrapper.get('[data-field="from"]').element as HTMLInputElement).value).toBe('2026-09-21');
    expect((wrapper.get('[data-field="to"]').element as HTMLInputElement).value).toBe('2026-10-20');

    mocked.fetchOverview.mockResolvedValue({ ...OVERVIEW, mode: 'test', last_synced_at: null, mrr: [], money: [] });
    await wrapper.get('[data-mode="test"]').trigger('click');
    await flushPromises();
    expect(mocked.fetchOverview).toHaveBeenLastCalledWith({ mode: 'test', from: '2026-09-21', to: '2026-10-20' });
    expect(wrapper.get('[data-incomplete]').text()).toContain('это не нулевая выручка');
  });

  it('shows the plugin funnel for the same period with conversion between the stages', async () => {
    mocked.fetchOverview.mockResolvedValue(OVERVIEW);
    const { wrapper } = await open(['billing.read']);

    expect(mocked.fetchPluginFunnel).toHaveBeenCalledWith({});
    const section = wrapper.get('[data-section="plugin-funnel"]');
    expect(section.get('[data-stage="click"]').text()).toContain('Нажатие в плагине');
    expect(section.get('[data-stage="click"]').text()).toContain('—');
    expect(section.get('[data-stage="signup"]').text()).toMatch(/33,3\s?%/);
    expect(section.get('[data-stage="connected"]').text()).toContain('8');
    expect(section.get('[data-placement="plugins_card"]').text()).toContain('Карточка на экране «Плагины»');

    await wrapper.get('[data-action="apply"]').trigger('click');
    await wrapper.get('[data-form="overview"]').trigger('submit');
    await flushPromises();
    expect(mocked.fetchPluginFunnel).toHaveBeenLastCalledWith({ from: '2026-09-21', to: '2026-10-20' });
  });

  it('shows staff without billing access a plain start page without loading figures', async () => {
    const { wrapper } = await open(['contacts.read']);

    expect(mocked.fetchOverview).not.toHaveBeenCalled();
    expect(mocked.fetchPluginFunnel).not.toHaveBeenCalled();
    expect(wrapper.find('[data-form="overview"]').exists()).toBe(false);
  });
});
