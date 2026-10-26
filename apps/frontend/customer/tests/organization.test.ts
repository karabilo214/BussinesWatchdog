import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import * as api from '@/api/account';
import * as billing from '@/api/billing';
import * as storesApi from '@/api/stores';
import type { AuthSession, OrganizationDeletionState } from '@/api/types';
import { localization, setLocale } from '@/i18n';
import OrganizationView from '@/views/OrganizationView.vue';
import { permissionsFor } from './permissions';

const session = ref<AuthSession | null>(null);
const load = vi.fn(async () => session.value);

vi.mock('@/api/account', () => ({
  getOrganizationDeletion: vi.fn(),
  requestOrganizationDeletion: vi.fn(),
  cancelOrganizationDeletion: vi.fn(),
  resendEmailVerification: vi.fn(),
  listMembers: vi.fn(async () => ({ data: [], assignable_roles: [] })),
  listInvitations: vi.fn(async () => []),
  getOrganizationFacts: vi.fn(async () => ({ name: 'Kaffee Rösterei', created_at: null, locale: null, timezone: null, owner_name: null, payment_providers: [], notification_channels: null })),
}));
vi.mock('@/api/stores', () => ({ listStores: vi.fn(async () => []) }));
vi.mock('@/api/billing', () => ({ fetchSubscription: vi.fn(async () => null), fetchPlans: vi.fn(async () => ({ data: [] })) }));
vi.mock('@/api/maintenance', () => ({ getMaintenance: vi.fn(async () => null) }));
vi.mock('@/composables/useSession', () => ({
  useSession: () => ({ session, load, signOut: vi.fn(), replace: vi.fn() }),
}));

const mocked = vi.mocked(api);

function sessionFor(role: 'owner' | 'admin', mfa = false): AuthSession {
  return {
    user: { id: 'u-me', name: 'Me', salutation: null, first_name: null, last_name: 'Me', company_name: null, email: 'me@example.test', locale: 'ru', email_verified: true, mfa_enabled: mfa, pending_email_change: null },
    active_tenant_id: 't-1',
    memberships: [{ tenant_id: 't-1', role, permissions: permissionsFor(role), created_at: '2026-10-01T00:00:00Z' }],
  };
}

function state(overrides: Partial<OrganizationDeletionState> = {}): OrganizationDeletionState {
  return { tenant_name: 'Kaffee Rösterei', tenant_status: 'active', can_request: true, request: null, ...overrides };
}

const scheduled: OrganizationDeletionState['request'] = {
  id: 'r-1', status: 'requested', requested_via: 'owner', requested_at: '2026-10-20T10:00:00Z', purge_after: '2026-11-19T10:00:00Z', can_cancel: true,
};

async function mountView() {
  const router = createRouter({
    history: createMemoryHistory('/app/'),
    routes: ['overview', 'profile', 'team', 'billing', 'organization', 'login', 'incidents', 'reconciliation', 'checks', 'notifications'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  await router.push('/organization');
  const wrapper = mount(OrganizationView, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
  await flushPromises();

  return { wrapper, router };
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
});

describe('organization summary', () => {
  it('shows the organisation, subscription, stores and team above the deletion block, hiding what the role may not read', async () => {
    session.value = sessionFor('owner');
    mocked.getOrganizationDeletion.mockResolvedValue(state());
    vi.mocked(storesApi.listStores).mockResolvedValue([
      { id: 's-1', name: 'Rösterei Shop', platform: 'shopware', status: 'active', capacity_paused_at: null },
      { id: 's-2', name: 'Outlet', platform: 'woocommerce', status: 'paused', capacity_paused_at: '2026-10-20T00:00:00Z' },
    ] as never);
    mocked.listMembers.mockResolvedValue({ data: [
      { user_id: 'u-me', tenant_id: 't-1', name: 'Me', salutation: null, first_name: null, last_name: 'Me', company_name: null, email: 'me@example.test', mfa_enabled: true, role: 'owner', is_you: true },
      { user_id: 'u-2', tenant_id: 't-1', name: 'Anna Berg', salutation: null, first_name: null, last_name: 'Anna Berg', company_name: null, email: 'anna@example.test', mfa_enabled: false, role: 'viewer', is_you: false },
    ], assignable_roles: [] } as never);
    mocked.listInvitations.mockResolvedValue([{ id: 'i-1', email: 'x@example.test', role: 'admin', permissions: permissionsFor('admin'), state: 'pending', invited_by: null, expires_at: '2026-10-30T00:00:00Z', created_at: null }]);
    vi.mocked(billing.fetchSubscription).mockResolvedValue({ plan_code: 'start', plan_version_id: 'v-start', status: 'active', paid_until: '2026-11-20T00:00:00Z', trial_ends_at: null, capacity: { limit: 3, stores: 2, paused: 1, choice_required: false } } as never);
    vi.mocked(billing.fetchPlans).mockResolvedValue({ data: [{ plan_version_id: 'v-start', plan_code: 'start', name: { ru: 'Старт', en: 'Start' } }] } as never);
    mocked.getOrganizationFacts.mockResolvedValue({
      name: 'Kaffee Rösterei', created_at: '2026-10-01T10:00:00Z', locale: 'de', timezone: 'Europe/Berlin', owner_name: 'Anna Müller',
      payment_providers: [{ provider: 'stripe', stores: 2, attention: 1 }], notification_channels: { enabled: 2, unverified: 1, failing: 0 },
    });
    const { wrapper } = await mountView();

    const summary = wrapper.get('[data-panel="organization-summary"]');
    const facts = summary.get('[data-summary="facts"]').text();
    expect(facts).toContain('Anna Müller');
    expect(facts).toContain('Deutsch');
    expect(facts).toContain('Europe/Berlin');
    expect(summary.get('[data-summary-provider="stripe"]').text()).toContain('Stripe');
    expect(summary.get('[data-summary-provider="stripe"]').text()).toContain('требует внимания: 1');
    expect(summary.get('[data-summary="channels"]').text()).toContain('Каналы уведомлений: 2');
    expect(summary.get('[data-summary="channels"]').text()).toContain('не подтверждено: 1');
    expect(summary.text()).toContain('Kaffee Rösterei');
    expect(summary.get('[data-summary="subscription"]').text()).toContain('Старт · Оплачена');
    expect(summary.get('[data-summary="stores"]').text()).toContain('Магазины: 2 из 3');
    expect(summary.get('[data-summary-store="s-2"]').text()).toContain('приостановлен по лимиту тарифа');
    expect(summary.get('[data-summary="team"]').text()).toContain('Anna Berg');
    expect(summary.get('[data-summary="team"]').text()).not.toContain('anna@example.test');
    expect(summary.get('[data-summary="invitations"]').text()).toContain('1');
    const html = wrapper.html();
    expect(html.indexOf('organization-summary')).toBeLessThan(html.indexOf('delete-organization'));

    vi.mocked(billing.fetchSubscription).mockRejectedValue(new ApiError(403, 'forbidden'));
    mocked.listMembers.mockRejectedValue(new ApiError(403, 'forbidden'));
    mocked.getOrganizationFacts.mockResolvedValue({ name: 'Kaffee Rösterei', created_at: null, locale: null, timezone: null, owner_name: null, payment_providers: [], notification_channels: null });
    const second = await mountView();
    expect(second.wrapper.find('[data-summary="channels"]').exists()).toBe(false);
    expect(second.wrapper.get('[data-summary="providers"]').text()).toContain('не подключены');
    expect(second.wrapper.find('[data-summary="subscription"]').exists()).toBe(false);
    expect(second.wrapper.find('[data-summary="team"]').exists()).toBe(false);
    expect(second.wrapper.find('[data-summary="stores"]').exists()).toBe(true);
  });
});

describe('organization deletion', () => {
  it('explains the three separate actions and lets only the owner delete', async () => {
    session.value = sessionFor('admin');
    mocked.getOrganizationDeletion.mockResolvedValue(state({ can_request: false }));
    const { wrapper } = await mountView();

    expect(wrapper.text()).toContain('приостановите мониторинг');
    expect(wrapper.text()).toContain('отмените подписку');
    expect(wrapper.text()).toContain('Удалить организацию может только её владелец.');
    expect(wrapper.find('[data-action="open-delete"]').exists()).toBe(false);
  });

  it('needs the exact name, the password and the code, then shows the scheduled deletion', async () => {
    session.value = sessionFor('owner', true);
    mocked.getOrganizationDeletion.mockResolvedValue(state());
    mocked.requestOrganizationDeletion
      .mockRejectedValueOnce(new ApiError(422, 'validation_failed', { code: ['mfa_code_invalid'] }))
      .mockResolvedValueOnce(state({ tenant_status: 'deleting', can_request: false, request: scheduled }));
    const { wrapper } = await mountView();

    await wrapper.get('[data-action="open-delete"]').trigger('click');
    const submit = () => wrapper.get('[data-action="confirm-delete"]');
    await wrapper.get('[data-field="organization-name"]').setValue('Kaffee');
    await wrapper.get('[data-form="delete-organization"] input[type="password"]').setValue('very-secure-password');
    await wrapper.get('[data-form="delete-organization"] input[autocomplete="one-time-code"]').setValue('123456');
    expect(submit().attributes('disabled')).toBeDefined();

    await wrapper.get('[data-field="organization-name"]').setValue('Kaffee Rösterei');
    expect(submit().attributes('disabled')).toBeUndefined();
    await wrapper.get('[data-form="delete-organization"]').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Код не подошёл');
    expect(mocked.requestOrganizationDeletion).toHaveBeenLastCalledWith('Kaffee Rösterei', 'very-secure-password', '123456');

    await wrapper.get('[data-form="delete-organization"] input[type="password"]').setValue('very-secure-password');
    await wrapper.get('[data-form="delete-organization"]').trigger('submit');
    await flushPromises();

    expect(load).toHaveBeenCalledWith(true);
    expect(wrapper.get('[data-panel="deletion-scheduled"]').text()).toContain('Kaffee Rösterei');
    expect(wrapper.find('[data-form="cancel-deletion"]').exists()).toBe(true);
  });

  it('lets the owner cancel within the 30 days and sends others to the owner', async () => {
    session.value = sessionFor('owner');
    mocked.getOrganizationDeletion.mockResolvedValue(state({ tenant_status: 'deleting', can_request: false, request: scheduled }));
    mocked.cancelOrganizationDeletion.mockResolvedValue(state());
    const { wrapper, router } = await mountView();

    expect(wrapper.text()).toContain('Отменить удаление');
    await wrapper.get('[data-form="cancel-deletion"] input[type="password"]').setValue('very-secure-password');
    await wrapper.get('[data-form="cancel-deletion"]').trigger('submit');
    await flushPromises();

    expect(mocked.cancelOrganizationDeletion).toHaveBeenCalledWith('very-secure-password', null);
    expect(router.currentRoute.value.name).toBe('overview');

    session.value = sessionFor('admin');
    mocked.getOrganizationDeletion.mockResolvedValue(state({ tenant_status: 'deleting', can_request: false, request: { ...scheduled!, can_cancel: false } }));
    const second = await mountView();
    expect(second.wrapper.text()).toContain('Отменить удаление может владелец организации.');
    expect(second.wrapper.find('[data-form="cancel-deletion"]').exists()).toBe(false);
  });
});
