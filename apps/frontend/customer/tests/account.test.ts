import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import * as api from '@/api/account';
import type { AuthSession } from '@/api/types';
import PasswordFields from '@/components/account/PasswordFields.vue';
import { localization, setLocale } from '@/i18n';
import InvitationView from '@/views/InvitationView.vue';
import TeamView from '@/views/TeamView.vue';

const session = ref<AuthSession | null>(null);

vi.mock('@/api/account', () => ({
  listMembers: vi.fn(),
  listInvitations: vi.fn(),
  changeMemberRole: vi.fn(),
  removeMember: vi.fn(),
  inviteMember: vi.fn(),
  revokeInvitation: vi.fn(),
  lookupInvitation: vi.fn(),
  acceptInvitation: vi.fn(),
  registerWithInvitation: vi.fn(),
  resendEmailVerification: vi.fn(),
}));

vi.mock('@/composables/useSession', () => ({
  useSession: () => ({
    session,
    load: vi.fn(async () => session.value),
    replace: vi.fn((value: AuthSession) => {
      session.value = value;
    }),
    signOut: vi.fn(async () => {
      session.value = null;
    }),
  }),
}));

const mocked = vi.mocked(api);

function sessionFor(role: 'owner' | 'admin' | 'viewer', email = 'me@example.test'): AuthSession {
  return {
    user: { id: 'u-me', name: 'Me', email, locale: 'ru', email_verified: true, mfa_enabled: false },
    active_tenant_id: 't-1',
    memberships: [{ tenant_id: 't-1', role, created_at: '2026-10-01T00:00:00Z' }],
  };
}

async function mountWith(component: object, path: string) {
  const router = createRouter({
    history: createMemoryHistory('/app/'),
    routes: ['overview', 'login', 'profile', 'team', 'incidents', 'reconciliation', 'checks', 'notifications', 'invitation'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  await router.push(path);

  return mount(component, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
});

describe('PasswordFields', () => {
  it('reports a password only when it has 12+ characters and both fields match', async () => {
    const wrapper = mount(PasswordFields, { props: { idPrefix: 't' }, global: { plugins: [localization] } });

    await wrapper.get('#t-password').setValue('short');
    expect(wrapper.text()).toContain('От 12 до 128 символов.');
    await wrapper.get('#t-password').setValue('a-long-enough-password');
    await wrapper.get('#t-confirmation').setValue('a-long-enough-passwor');
    expect(wrapper.text()).toContain('Пароли не совпадают.');
    expect(wrapper.emitted('update')!.at(-1)).toEqual(['a-long-enough-password', false]);
    await wrapper.get('#t-confirmation').setValue('a-long-enough-password');
    expect(wrapper.emitted('update')!.at(-1)).toEqual(['a-long-enough-password', true]);
  });
});

describe('TeamView', () => {
  const members = [
    { user_id: 'u-owner', tenant_id: 't-1', name: 'Olga', email: 'olga@example.test', role: 'owner' as const, is_you: false, created_at: null },
    { user_id: 'u-me', tenant_id: 't-1', name: 'Me', email: 'me@example.test', role: 'admin' as const, is_you: true, created_at: null },
    { user_id: 'u-admin2', tenant_id: 't-1', name: 'Anna', email: 'anna@example.test', role: 'admin' as const, is_you: false, created_at: null },
    { user_id: 'u-op', tenant_id: 't-1', name: 'Oleg', email: 'oleg@example.test', role: 'operator' as const, is_you: false, created_at: null },
  ];

  it('lets an admin manage only operators and viewers and invite with those roles', async () => {
    session.value = sessionFor('admin');
    mocked.listMembers.mockResolvedValue({ data: members, assignable_roles: ['operator', 'viewer'] });
    mocked.listInvitations.mockResolvedValue([]);
    mocked.changeMemberRole.mockResolvedValue(members[3]!);
    const wrapper = await mountWith(TeamView, '/team');
    await flushPromises();

    expect(wrapper.find('[data-member="olga@example.test"] select').exists()).toBe(false);
    expect(wrapper.find('[data-member="anna@example.test"] select').exists()).toBe(false);
    expect(wrapper.find('[data-member="me@example.test"] select').exists()).toBe(false);
    expect(wrapper.get('[data-member="me@example.test"]').text()).toContain('Выйти из команды');
    expect(wrapper.get('[data-member="olga@example.test"]').text()).not.toContain('Удалить');

    await wrapper.get('[data-member="oleg@example.test"] select').setValue('viewer');
    await flushPromises();
    expect(mocked.changeMemberRole).toHaveBeenCalledWith('u-op', 'viewer');

    const roles = wrapper.findAll('[data-panel="invite"] option').map((option) => option.attributes('value'));
    expect(roles).toEqual(['operator', 'viewer']);
  });

  it('removes a member only after confirmation', async () => {
    session.value = sessionFor('owner');
    mocked.listMembers.mockResolvedValue({ data: members, assignable_roles: ['admin', 'operator', 'viewer'] });
    mocked.listInvitations.mockResolvedValue([]);
    mocked.removeMember.mockResolvedValue();
    const wrapper = await mountWith(TeamView, '/team');
    await flushPromises();

    await wrapper.get('[data-member="oleg@example.test"]').findAll('button').find((button) => button.text() === 'Удалить')!.trigger('click');
    expect(mocked.removeMember).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('Oleg потеряет доступ к команде сразу.');
    await wrapper.get('[data-member="oleg@example.test"]').findAll('button').find((button) => button.text() === 'Удалить')!.trigger('click');
    await flushPromises();
    expect(mocked.removeMember).toHaveBeenCalledWith('u-op');
  });
});

describe('InvitationView', () => {
  const lookup = { tenant_name: 'Kaffee GmbH', email: 'new@example.test', role: 'operator' as const, expires_at: '2026-10-17T10:00:00Z', account_exists: false };

  it('lets a new person create an account for the invited address', async () => {
    session.value = null;
    mocked.lookupInvitation.mockResolvedValue(lookup);
    mocked.registerWithInvitation.mockResolvedValue(sessionFor('viewer', 'new@example.test'));
    const wrapper = await mountWith(InvitationView, '/invitation?token=abc');
    await flushPromises();

    expect(wrapper.text()).toContain('«Kaffee GmbH» с ролью «оператор»');
    await wrapper.get('#invite-name').setValue('Neu');
    await wrapper.get('#invite-password').setValue('a-long-enough-password');
    await wrapper.get('#invite-confirmation').setValue('a-long-enough-password');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(mocked.registerWithInvitation).toHaveBeenCalledWith({ token: 'abc', name: 'Neu', email: 'new@example.test', password: 'a-long-enough-password', locale: 'ru' });
  });

  it('asks an existing account to sign in, and warns when signed in with another address', async () => {
    session.value = null;
    mocked.lookupInvitation.mockResolvedValue({ ...lookup, account_exists: true });
    const signedOut = await mountWith(InvitationView, '/invitation?token=abc');
    await flushPromises();
    expect(signedOut.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'login', query: { redirect: '/invitation?token=abc' } });

    session.value = sessionFor('owner', 'someone@example.test');
    const other = await mountWith(InvitationView, '/invitation?token=abc');
    await flushPromises();
    expect(other.text()).toContain('Вы вошли как someone@example.test');
    expect(other.text()).not.toContain('Принять приглашение');
  });

  it('says an invalid invitation is invalid, and shows other failures as errors', async () => {
    session.value = null;
    mocked.lookupInvitation.mockRejectedValue(new ApiError(422, 'invitation_invalid'));
    const invalid = await mountWith(InvitationView, '/invitation?token=old');
    await flushPromises();
    expect(invalid.find('[data-invalid]').exists()).toBe(true);

    mocked.lookupInvitation.mockRejectedValue(new ApiError(0, 'network_unavailable'));
    const offline = await mountWith(InvitationView, '/invitation?token=abc');
    await flushPromises();
    expect(offline.find('[data-invalid]').exists()).toBe(false);
    expect(offline.text()).toContain('Нет связи с сервером');
  });
});

describe('account error codes', () => {
  it('every rejection code of the account and team backend is translated', async () => {
    const { readdirSync, readFileSync } = await import('node:fs');
    const { resolve } = await import('node:path');
    const en = (await import('@/i18n/locales/en.json')).default;
    const dirs = ['../../../backend/app/Support/Account', '../../../backend/app/Http/Controllers/Api/V1/Account', '../../../backend/app/Http/Controllers/Api/V1/Auth'].map((dir) => resolve(__dirname, dir));
    const source = dirs.flatMap((dir) => readdirSync(dir).map((name) => readFileSync(resolve(dir, name), 'utf8'))).join('\n');
    const codes = new Set([...source.matchAll(/AccountRejected\('([a-z_]+)'/g), ...source.matchAll(/\? '([a-z_]+)' : '([a-z_]+)'/g)].flatMap((match) => match.slice(1).filter(Boolean)));

    expect(codes.size).toBeGreaterThan(8);

    for (const code of [...codes, 'tenant_forbidden']) {
      expect((en.errors as Record<string, string>)[code], code).toBeTruthy();
    }
  });
});
