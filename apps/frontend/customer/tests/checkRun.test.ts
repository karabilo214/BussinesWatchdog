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
  it('explains why the payment step was skipped when gateway scripts were not loaded', async () => {
    setLocale('ru');
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
    expect(reasons[0]!.text()).toContain('Путь до страницы оплаты пройден');
    expect(wrapper.get('[data-step="cart"]').find('[data-skip-reason]').exists()).toBe(false);
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
