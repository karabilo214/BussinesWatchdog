import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import * as checks from '@/api/checks';
import type { AuthSession, CheckRunDetail } from '@/api/types';
import { localization, setLocale } from '@/i18n';
import CheckRunView from '@/views/CheckRunView.vue';
import { permissionsFor } from './permissions';

const session = ref<AuthSession | null>({
  user: { id: 'u-1', name: 'Me', salutation: null, first_name: null, last_name: 'Me', company_name: null, email: 'me@example.test', locale: 'ru', email_verified: true, mfa_enabled: true, pending_email_change: null },
  active_tenant_id: 't-1',
  memberships: [{ tenant_id: 't-1', role: 'owner', permissions: permissionsFor('owner'), created_at: '2026-10-01T00:00:00Z' }],
});

vi.mock('@/api/checks', () => ({ getRun: vi.fn(), cancelRun: vi.fn() }));
vi.mock('@/api/incidents', () => ({ artifactLink: vi.fn() }));
vi.mock('@/api/maintenance', () => ({ getMaintenance: vi.fn(async () => null) }));
vi.mock('@/api/account', () => ({ resendEmailVerification: vi.fn() }));
vi.mock('@/composables/useSession', () => ({ useSession: () => ({ session, load: vi.fn(), signOut: vi.fn(), replace: vi.fn() }) }));

const step = (index: number, code: string, status: 'passed' | 'skipped' | 'failed', assertions: { code: string; passed: boolean; detail_code?: string }[] = []) => ({
  index, code, status, error_code: null, assertions, started_at: '2026-10-20T10:00:00Z', finished_at: '2026-10-20T10:00:01Z',
});

describe('check run', () => {
  it.each(['ru', 'en', 'de'] as const)('links the skipped payment explanation to its store setting in %s', async (locale) => {
    setLocale(locale);
    const run: CheckRunDetail = {
      id: 'r-1', store_id: 's-1', scenario_id: 'sc-1', scenario_version: 1, trigger: 'scheduled', status: 'passed', error_code: null,
      scheduled_at: '2026-10-20T10:00:00Z', started_at: '2026-10-20T10:00:00Z', finished_at: '2026-10-20T10:01:00Z',
      attempts: [{
        id: 'a-1', attempt_number: 1, status: 'passed', error_code: null, location: 'eu-de', browser_version: 'chromium', started_at: '2026-10-20T10:00:00Z', finished_at: '2026-10-20T10:01:00Z',
        diagnostics: null, artifacts: [],
        steps: [step(0, 'cart', 'passed'), step(1, 'payment_form', 'skipped', [{ code: 'payment_methods_not_verified', passed: true, detail_code: 'gateway_scripts_not_loaded' }])],
      }],
    };
    vi.mocked(checks.getRun).mockResolvedValue(run);
    const router = createRouter({ history: createMemoryHistory('/app/'), routes: ['overview', 'checks', 'profile', 'incidents', 'reconciliation', 'notifications', 'login'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })) });
    await router.push('/checks');

    const wrapper = mount(CheckRunView, { props: { id: 'r-1' }, global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    const reasons = wrapper.findAll('[data-skip-reason]');
    expect(reasons).toHaveLength(1);
    expect(reasons[0]!.text()).toContain({ ru: 'Путь до страницы оплаты пройден', en: 'The path to the payment page passed', de: 'Der Weg bis zur Zahlungsseite ist bestanden' }[locale]);
    const link = wrapper.findAllComponents(RouterLinkStub).find((item) => item.attributes('data-payment-settings-link') !== undefined)!;
    expect(link.props('to')).toEqual({ name: 'checks', query: { store: 's-1' }, hash: '#scenario-providers' });
    const label = { ru: 'Проверять формы оплаты полностью', en: 'Check payment forms fully', de: 'Zahlungsformulare vollständig prüfen' }[locale];
    expect(link.text()).toBe(label);
    expect(reasons[0]!.text()).not.toContain('{setting}');
    session.value!.memberships[0]!.permissions = permissionsFor('viewer');
    await flushPromises();
    expect(wrapper.find('[data-payment-settings-link]').exists()).toBe(false);
    expect(reasons[0]!.text()).toContain(label);
    session.value!.memberships[0]!.permissions = permissionsFor('owner');
    wrapper.unmount();
    expect(wrapper.get('[data-step="cart"]').find('[data-skip-reason]').exists()).toBe(false);
  });

  it('names a product page that fails with the product in the cart and warns about server errors on the way', async () => {
    setLocale('ru');
    const failed = { ...step(2, 'product_again', 'failed', [{ code: 'product_again_check', passed: false, detail_code: 'product_page_fails_with_cart' }]), error_code: 'site_failure' };
    const warned = step(1, 'add_to_cart', 'passed', [{ code: 'add_to_cart_submitted', passed: true }, { code: 'store_server_error', passed: true, detail_code: 'http_500' }]);
    const run: CheckRunDetail = {
      id: 'r-3', store_id: 's-1', scenario_id: 'sc-1', scenario_version: 1, trigger: 'manual', status: 'failed', error_code: 'site_failure',
      scheduled_at: '2026-10-20T10:00:00Z', started_at: '2026-10-20T10:00:00Z', finished_at: '2026-10-20T10:01:00Z',
      attempts: [{
        id: 'a-3', attempt_number: 1, status: 'failed', error_code: 'site_failure', location: 'eu-de', browser_version: 'firefox-141.0', started_at: '2026-10-20T10:00:00Z', finished_at: '2026-10-20T10:01:00Z',
        diagnostics: null, artifacts: [],
        steps: [step(0, 'product', 'passed'), warned, failed],
      }],
    };
    vi.mocked(checks.getRun).mockResolvedValue(run);
    const router = createRouter({ history: createMemoryHistory('/app/'), routes: ['overview', 'checks', 'profile', 'incidents', 'reconciliation', 'notifications', 'login'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })) });
    await router.push('/checks');

    const wrapper = mount(CheckRunView, { props: { id: 'r-3' }, global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(wrapper.get('[data-step="product_again"]').text()).toContain('страница товара с товаром в корзине');
    expect(wrapper.get('[data-step="product_again"] [data-failure-reason]').text()).toContain('когда этот товар уже лежит в корзине');
    expect(wrapper.get('[data-step="add_to_cart"] [data-step-warning]').text()).toContain('ошибкой сервера (500)');
    expect(wrapper.get('[data-step="product"]').find('[data-step-warning]').exists()).toBe(false);
  });

  it('names the cause when the payment provider rejected the store key', async () => {
    setLocale('en');
    const failed = { ...step(1, 'payment_form', 'failed', [{ code: 'payment_form_check', passed: false, detail_code: 'payment_provider_rejected' }]), error_code: 'site_failure' };
    const run: CheckRunDetail = {
      id: 'r-2', store_id: 's-1', scenario_id: 'sc-1', scenario_version: 1, trigger: 'scheduled', status: 'failed', error_code: 'site_failure',
      scheduled_at: '2026-10-20T10:00:00Z', started_at: '2026-10-20T10:00:00Z', finished_at: '2026-10-20T10:01:00Z',
      attempts: [{
        id: 'a-2', attempt_number: 1, status: 'failed', error_code: 'site_failure', location: 'eu-de', browser_version: 'chromium', started_at: '2026-10-20T10:00:00Z', finished_at: '2026-10-20T10:01:00Z',
        diagnostics: null, artifacts: [],
        steps: [step(0, 'cart', 'passed'), failed],
      }],
    };
    vi.mocked(checks.getRun).mockResolvedValue(run);
    const router = createRouter({ history: createMemoryHistory('/app/'), routes: ['overview', 'checks', 'profile', 'incidents', 'reconciliation', 'notifications', 'login'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })) });
    await router.push('/checks');

    const wrapper = mount(CheckRunView, { props: { id: 'r-2' }, global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(wrapper.get('[data-step="payment_form"] [data-failure-reason]').text()).toContain("rejected your store's key");
    expect(wrapper.get('[data-step="cart"]').find('[data-failure-reason]').exists()).toBe(false);
  });
});
