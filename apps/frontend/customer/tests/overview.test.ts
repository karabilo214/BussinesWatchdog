import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import * as overviewApi from '@/api/overview';
import * as storesApi from '@/api/stores';
import type { AuthSession, Store } from '@/api/types';
import { lastSuccessfulCheck, verdict } from '@/components/overview/verdict';
import { localization, setLocale } from '@/i18n';
import AppLayout from '@/layouts/AppLayout.vue';
import OverviewView from '@/views/OverviewView.vue';
import { makeStore } from './fixtures';

vi.mock('@/api/overview', () => ({ getOverview: vi.fn() }));
vi.mock('@/api/stores', () => ({ listStores: vi.fn() }));
const session = ref<AuthSession | null>(null);
vi.mock('@/composables/useSession', () => ({ useSession: () => ({ session, load: vi.fn(async () => session.value) }) }));

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
  it('warns a user without MFA even before a store is added and hides the warning after enrollment', async () => {
    setLocale('ru');
    const currentSession: AuthSession = {
      user: { id: 'u-1', name: 'Owner', email: 'owner@example.test', locale: 'ru', email_verified: true, mfa_enabled: false },
      active_tenant_id: 't-1',
      memberships: [{ tenant_id: 't-1', role: 'owner', created_at: '2026-10-01T00:00:00Z' }],
    };
    session.value = currentSession;
    vi.mocked(storesApi.listStores).mockResolvedValue([]);
    vi.mocked(overviewApi.getOverview).mockResolvedValue({ incidents: { active: 0, by_severity: { critical: 0, warning: 0, info: 0 }, latest: [] }, discrepancies: [], unknown_amount_incidents: 0, recent_checks: [] });
    const router = createRouter({ history: createMemoryHistory('/app/'), routes: [{ path: '/:p(.*)*', name: 'overview', component: OverviewView }] });
    const wrapper = mount(OverviewView, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(wrapper.get('[data-mfa-warning]').text()).toContain('MFA не подключена');
    expect(wrapper.get('[data-mfa-warning]').findComponent(RouterLinkStub).props('to')).toEqual({ name: 'profile' });
    for (const role of ['admin', 'operator', 'viewer'] as const) {
      session.value = { ...currentSession, memberships: [{ ...currentSession.memberships[0]!, role }] };
      await flushPromises();
      expect(wrapper.get('[data-mfa-warning]').text()).toContain('Включить MFA');
    }
    session.value = { ...currentSession, user: { ...currentSession.user, mfa_enabled: true } };
    await flushPromises();
    expect(wrapper.find('[data-mfa-warning]').exists()).toBe(false);
    session.value = null;
  });

  it('shows discrepancies per currency and kind, never added together, and unknown amounts separately', async () => {
    setLocale('ru');
    vi.mocked(storesApi.listStores).mockResolvedValue([covered()]);
    vi.mocked(overviewApi.getOverview).mockResolvedValue({
      incidents: { active: 3, by_severity: { critical: 1, warning: 2, info: 0 }, latest: [] },
      discrepancies: [
        { mode: 'live', currency: 'EUR', currency_exponent: 2, component: 'capture', total_minor: '26100', incident_count: 2 },
        { mode: 'test', currency: 'EUR', currency_exponent: 2, component: 'refund', total_minor: '500', incident_count: 1 },
        { mode: null, currency: 'USD', currency_exponent: null, component: 'capture', total_minor: '1000', incident_count: 1 },
      ],
      unknown_amount_incidents: 1,
      capacity_paused_stores: 1,
      recent_checks: [],
    });
    const router = createRouter({ history: createMemoryHistory('/app/'), routes: [{ path: '/:p(.*)*', name: 'overview', component: OverviewView }] });
    const wrapper = mount(OverviewView, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(wrapper.get('[data-verdict]').attributes('data-verdict')).toBe('problems');
    expect(wrapper.get('[data-discrepancy="live-EUR-capture"]').text()).toContain('261,00');
    expect(wrapper.get('[data-discrepancy="test-EUR-refund"]').text()).toContain('5,00');
    expect(wrapper.get('[data-discrepancy="unknown-USD-capture"]').text()).toContain('1000 USD');
    expect(wrapper.get('[data-discrepancy="test-EUR-refund"]').text()).toContain('Тестовый режим');
    expect(wrapper.text()).not.toContain('266,00');
    expect(wrapper.text()).toContain('Инцидентов без подтверждённой суммы: 1');
    expect(wrapper.get('[data-paused-stores]').text()).toContain('Магазинов на паузе по тарифу: 1');
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
