import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import * as overviewApi from '@/api/overview';
import * as storesApi from '@/api/stores';
import type { Store } from '@/api/types';
import { lastSuccessfulCheck, verdict } from '@/components/overview/verdict';
import { localization, setLocale } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import OverviewView from '@/views/OverviewView.vue';
import { makeStore } from './fixtures';

vi.mock('@/api/overview', () => ({ getOverview: vi.fn() }));
vi.mock('@/api/stores', () => ({ listStores: vi.fn() }));

function covered(overrides: Partial<Store> = {}): Store {
  return makeStore({
    coverage: {
      connector: { state: 'fresh', provider: 'woocommerce', last_heartbeat_at: null, plugin_version: null },
      money: { state: 'reconciling', providers: [] },
      payment_attempts: { state: 'observing', last_window_end_at: null },
      browser_checks: { state: 'passing', last_run_status: 'passed', last_run_error_code: null, last_run_finished_at: null, next_due_at: null },
    },
    ...overrides,
  });
}

describe('verdict', () => {
  it('says "no problems" only when every store is fully covered and nothing is active', () => {
    expect(verdict([], 0)).toBe('no_stores');
    expect(verdict([covered()], 1)).toBe('problems');
    expect(verdict([covered(), makeStore()], 0)).toBe('incomplete');
    expect(verdict([covered({ coverage: { ...covered().coverage, money: { state: 'provider_not_connected', providers: [] } } })], 0)).toBe('incomplete');
    expect(verdict([covered(), covered({ id: 's-2' })], 0)).toBe('all_clear');
  });

  it('finds the latest successful check across stores', () => {
    expect(lastSuccessfulCheck([makeStore(), makeStore({ last_successful_check_at: '2026-10-09T10:00:00Z' }), makeStore({ last_successful_check_at: '2026-10-10T08:00:00Z' })])).toBe('2026-10-10T08:00:00Z');
    expect(lastSuccessfulCheck([makeStore()])).toBeNull();
  });
});

describe('OverviewView', () => {
  it('shows discrepancies per currency and kind, never added together, and unknown amounts separately', async () => {
    setLocale('ru');
    vi.mocked(storesApi.listStores).mockResolvedValue([covered()]);
    vi.mocked(overviewApi.getOverview).mockResolvedValue({
      incidents: { active: 3, by_severity: { critical: 1, warning: 2, info: 0 }, latest: [] },
      discrepancies: [
        { currency: 'EUR', currency_exponent: 2, component: 'capture', total_minor: '26100', incident_count: 2 },
        { currency: 'EUR', currency_exponent: 2, component: 'refund', total_minor: '500', incident_count: 1 },
        { currency: 'USD', currency_exponent: null, component: 'capture', total_minor: '1000', incident_count: 1 },
      ],
      unknown_amount_incidents: 1,
      recent_checks: [],
    });
    const router = createRouter({ history: createMemoryHistory('/app/'), routes: [{ path: '/:p(.*)*', name: 'overview', component: OverviewView }] });
    const wrapper = mount(OverviewView, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(wrapper.get('[data-verdict]').attributes('data-verdict')).toBe('problems');
    expect(wrapper.get('[data-discrepancy="EUR-capture"]').text()).toContain('261,00');
    expect(wrapper.get('[data-discrepancy="EUR-refund"]').text()).toContain('5,00');
    expect(wrapper.get('[data-discrepancy="USD-capture"]').text()).toContain('1000 USD');
    expect(wrapper.text()).not.toContain('266,00');
    expect(wrapper.text()).toContain('Инцидентов без подтверждённой суммы: 1');
    expect(wrapper.text()).toContain('не потери');
  });
});

describe('AppLayout mobile menu', () => {
  it('opens the menu, marks the current section and closes after navigation', async () => {
    setLocale('ru');
    const Stub = { template: '<div />' };
    const router = createRouter({
      history: createMemoryHistory('/app/'),
      routes: ['overview', 'incidents', 'incident', 'reconciliation', 'checks', 'notifications', 'login', 'profile'].map((name) => ({ path: `/${name}${name === 'incident' ? '/:id' : ''}`, name, component: Stub })),
    });
    await router.push('/incident/1');
    const wrapper = mount(AppLayout, { global: { plugins: [localization, router] } });
    const toggle = wrapper.get('button[aria-controls="mobile-menu"]');

    expect(toggle.attributes('aria-expanded')).toBe('false');
    expect(wrapper.find('#mobile-menu').exists()).toBe(false);

    await toggle.trigger('click');
    expect(toggle.attributes('aria-expanded')).toBe('true');
    const current = wrapper.get('#mobile-menu [aria-current="page"]');
    expect(current.text()).toBe('Инциденты');

    await router.push('/checks');
    await flushPromises();
    expect(wrapper.find('#mobile-menu').exists()).toBe(false);
  });
});
