import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import * as api from '@/api/account';
import type { AuthSession, OrganizationDeletionState } from '@/api/types';
import { localization, setLocale } from '@/i18n';
import OrganizationView from '@/views/OrganizationView.vue';

const session = ref<AuthSession | null>(null);
const load = vi.fn(async () => session.value);

vi.mock('@/api/account', () => ({
  getOrganizationDeletion: vi.fn(),
  requestOrganizationDeletion: vi.fn(),
  cancelOrganizationDeletion: vi.fn(),
  resendEmailVerification: vi.fn(),
}));
vi.mock('@/api/maintenance', () => ({ getMaintenance: vi.fn(async () => null) }));
vi.mock('@/composables/useSession', () => ({
  useSession: () => ({ session, load, signOut: vi.fn(), replace: vi.fn() }),
}));

const mocked = vi.mocked(api);

function sessionFor(role: 'owner' | 'admin', mfa = false): AuthSession {
  return {
    user: { id: 'u-me', name: 'Me', email: 'me@example.test', locale: 'ru', email_verified: true, mfa_enabled: mfa },
    active_tenant_id: 't-1',
    memberships: [{ tenant_id: 't-1', role, created_at: '2026-10-01T00:00:00Z' }],
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
