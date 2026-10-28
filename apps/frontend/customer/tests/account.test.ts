import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import * as api from '@/api/account';
import type { AuthSession, TeamMember, TeamRole, TenantPermission } from '@/api/types';
import EmailChangePanel from '@/components/account/EmailChangePanel.vue';
import PasswordFields from '@/components/account/PasswordFields.vue';
import { localization, setLocale } from '@/i18n';
import MfaPanel from '@/components/account/MfaPanel.vue';
import SessionsPanel from '@/components/account/SessionsPanel.vue';
import PasskeysPanel from '@/components/account/PasskeysPanel.vue';
import TrustedDevicesPanel from '@/components/account/TrustedDevicesPanel.vue';
import InvitationView from '@/views/InvitationView.vue';
import LoginView from '@/views/LoginView.vue';
import TeamView from '@/views/TeamView.vue';
import { assignableFor, CATALOG, permissionsFor } from './permissions';

const session = ref<AuthSession | null>(null);

vi.mock('@laravel/passkeys', () => ({ Passkeys: { isSupported: () => true }, UserCancelledError: class UserCancelledError extends Error {} }));

vi.mock('@/api/account', () => ({
  listMembers: vi.fn(),
  getMoneyVisibility: vi.fn(),
  listInvitations: vi.fn(),
  changeMemberPermissions: vi.fn(),
  requestEmailChange: vi.fn(),
  resendEmailChange: vi.fn(),
  cancelEmailChange: vi.fn(),
  changeInvitationPermissions: vi.fn(),
  removeMember: vi.fn(),
  inviteMember: vi.fn(),
  revokeInvitation: vi.fn(),
  lookupInvitation: vi.fn(),
  acceptInvitation: vi.fn(),
  registerWithInvitation: vi.fn(),
  resendEmailVerification: vi.fn(),
  transferOwnership: vi.fn(),
  resetMemberMfa: vi.fn(),
  fetchMfa: vi.fn(),
  beginMfaSetup: vi.fn(),
  confirmMfa: vi.fn(),
  disableMfa: vi.fn(),
  regenerateRecoveryCodes: vi.fn(),
  listAccountSessions: vi.fn(),
  revokeAllAccountSessions: vi.fn(),
  listPasskeys: vi.fn(),
  registerPasskey: vi.fn(),
  deletePasskey: vi.fn(),
  listTrustedDevices: vi.fn(),
  revokeTrustedDevice: vi.fn(),
}));

const completeMfa = vi.fn();
const signIn = vi.fn();
const signInWithPasskey = vi.fn();

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
    clear: vi.fn(() => {
      session.value = null;
    }),
    signIn,
    signInWithPasskey,
    completeMfa,
  }),
}));

const mocked = vi.mocked(api);

function sessionFor(role: 'owner' | 'admin' | 'operator' | 'viewer', email = 'me@example.test'): AuthSession {
  return {
    user: { id: 'u-me', name: 'Me', salutation: null, first_name: null, last_name: 'Me', company_name: null, email, locale: 'ru', email_verified: true, mfa_enabled: false, pending_email_change: null },
    active_tenant_id: 't-1',
    memberships: [{ tenant_id: 't-1', role, permissions: permissionsFor(role), created_at: '2026-10-01T00:00:00Z' }],
  };
}

async function mountWith(component: object, path: string) {
  const router = createRouter({
    history: createMemoryHistory('/app/'),
    routes: ['overview', 'login', 'forgot-password', 'profile', 'team', 'incidents', 'reconciliation', 'checks', 'notifications', 'invitation'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  await router.push(path);

  return mount(component, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
}

beforeEach(() => {
  vi.clearAllMocks();
  mocked.getMoneyVisibility.mockResolvedValue({ can_view_money_amounts: true });
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

describe('PasskeysPanel', () => {
  it('lists key names without credential data and requires step-up to delete', async () => {
    session.value = sessionFor('owner');
    mocked.listPasskeys.mockResolvedValue([{ id: 7, name: 'Laptop', created_at: '2026-10-25T09:00:00Z', last_used_at: null }]);
    mocked.deletePasskey.mockResolvedValue();
    const wrapper = await mountWith(PasskeysPanel, '/profile');
    await flushPromises();

    expect(wrapper.text()).toContain('Laptop');
    await wrapper.get('button').trigger('click');
    await wrapper.get('#passkey-password').setValue('very-secure-password');
    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(mocked.deletePasskey).toHaveBeenCalledWith(7, 'very-secure-password', null);
  });
});

describe('TrustedDevicesPanel', () => {
  it('lists the current device and revokes only the chosen trust', async () => {
    session.value = sessionFor('owner');
    mocked.listTrustedDevices.mockResolvedValue([{ id: 'device-1', device: 'Firefox', current: true, last_used_at: null, expires_at: '2026-11-24T09:00:00Z' }]);
    mocked.revokeTrustedDevice.mockResolvedValue();
    const wrapper = await mountWith(TrustedDevicesPanel, '/profile');
    await flushPromises();

    expect(wrapper.text()).toContain('Firefox');
    expect(wrapper.text()).toContain('это устройство');
    await wrapper.get('button').trigger('click');
    await flushPromises();
    expect(mocked.revokeTrustedDevice).toHaveBeenCalledWith('device-1');
  });
});

describe('SessionsPanel', () => {
  it('shows only session metadata and signs out after step-up', async () => {
    session.value = sessionFor('owner');
    mocked.listAccountSessions.mockResolvedValue([{ current: true, device: 'Firefox', ip_address: '192.0.2.5', last_active_at: '2026-10-25T09:00:00Z' }]);
    mocked.revokeAllAccountSessions.mockResolvedValue();
    const wrapper = await mountWith(SessionsPanel, '/profile');
    await flushPromises();

    expect(wrapper.text()).toContain('Firefox');
    expect(wrapper.text()).toContain('192.0.2.5');
    expect(wrapper.text()).toContain('этот сеанс');
    expect(wrapper.find('#sessions-code').exists()).toBe(false);
    await wrapper.get('#sessions-password').setValue('very-secure-password');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(mocked.revokeAllAccountSessions).toHaveBeenCalledWith('very-secure-password', null);
    expect(session.value).toBeNull();
  });
});

function member(id: string, name: string, role: TeamMember['role'], extra: Partial<TeamMember> = {}): TeamMember {
  return { user_id: id, tenant_id: 't-1', name, email: `${name.toLowerCase()}@example.test`, role, permissions: permissionsFor(role), can_edit: false, mfa_enabled: false, is_you: false, created_at: null, ...extra };
}

/** The member list as the server returns it to a viewer with `role`. */
function team(data: Partial<TeamMember>[], role: TeamRole) {
  return {
    data: data.map((item) => ({ permissions: permissionsFor(item.role ?? 'viewer'), can_edit: role === 'owner' && item.role !== 'owner' && !item.is_you, ...item }) as TeamMember),
    assignable_roles: (['admin', 'operator', 'viewer'] as const).filter((preset) => CATALOG.presets[preset].every((permission) => assignableFor(role).includes(permission))),
    assignable_permissions: assignableFor(role),
    permission_catalog: CATALOG,
  };
}

describe('TeamView', () => {
  const members = [
    member('u-owner', 'Olga', 'owner'),
    member('u-me', 'Me', 'admin', { is_you: true }),
    member('u-admin2', 'Anna', 'admin', { mfa_enabled: true, can_edit: true }),
    member('u-op', 'Oleg', 'operator', { can_edit: true, permissions: permissionsFor('operator', false) }),
  ];

  it('offers permission changes only where the server allows them and saves the chosen set', async () => {
    session.value = sessionFor('admin');
    mocked.listMembers.mockResolvedValue(team(members, 'admin'));
    mocked.listInvitations.mockResolvedValue([]);
    mocked.changeMemberPermissions.mockResolvedValue(members[3]!);
    const wrapper = await mountWith(TeamView, '/team');
    await flushPromises();

    expect(wrapper.find('[data-member="olga@example.test"] [data-edit-permissions]').exists()).toBe(false);
    expect(wrapper.find('[data-member="me@example.test"] [data-edit-permissions]').exists()).toBe(false);
    expect(wrapper.get('[data-member="me@example.test"]').text()).toContain('Выйти из команды');
    expect(wrapper.get('[data-member="olga@example.test"]').text()).not.toContain('Удалить');
    expect(wrapper.get('[data-member="oleg@example.test"]').text()).toContain('без сумм');
    expect(wrapper.get('[data-member="oleg@example.test"]').text()).toContain('оператор');

    await wrapper.get('[data-member="oleg@example.test"] [data-edit-permissions]').trigger('click');
    const picker = wrapper.get('[data-member-permissions]');
    expect(picker.get('[data-preset="operator"]').attributes('aria-pressed')).toBe('true');
    expect(picker.get('[data-permission="billing.manage"]').attributes('disabled')).toBeDefined();
    expect(picker.get('[data-permission="stores.read"]').attributes('disabled')).toBeDefined();
    await picker.get('[data-permission="incidents.read"]').setValue(false);
    expect((picker.get('[data-permission="incidents.manage"]').element as HTMLInputElement).checked).toBe(false);
    expect(picker.get('[data-preset="custom"]').text()).toBe('своя настройка');
    await picker.get('[data-permission="incidents.snooze"]').setValue(true);
    await picker.get('[data-permission="money.view"]').setValue(true);
    await picker.trigger('submit');
    await flushPromises();

    expect(mocked.changeMemberPermissions).toHaveBeenCalledWith('u-op', ['stores.read', 'reconciliation.read', 'reconciliation.run', 'incidents.read', 'incidents.snooze', 'money.view']);
    expect(wrapper.text()).toContain('Разрешения сохранены.');
  });

  it('lets a limited team manager give only what they have and edit only invitations within it', async () => {
    const manager: TenantPermission[] = ['stores.read', 'incidents.read', 'team.manage'];
    session.value = { ...sessionFor('viewer'), memberships: [{ tenant_id: 't-1', role: 'custom', permissions: manager, created_at: '2026-10-01T00:00:00Z' }] };
    mocked.listMembers.mockResolvedValue({ ...team(members, 'viewer'), assignable_roles: [], assignable_permissions: manager });
    mocked.listInvitations.mockResolvedValue([
      { id: 'i-small', email: 'small@example.test', role: 'custom', permissions: ['stores.read', 'incidents.read'], state: 'pending', invited_by: null, expires_at: '2026-10-30T00:00:00Z', created_at: null },
      { id: 'i-big', email: 'big@example.test', role: 'viewer', permissions: permissionsFor('viewer'), state: 'pending', invited_by: null, expires_at: '2026-10-30T00:00:00Z', created_at: null },
    ]);
    mocked.inviteMember.mockResolvedValue({ id: 'i-new', email: 'new@example.test', role: 'custom', permissions: ['stores.read'], state: 'pending', invited_by: null, expires_at: '2026-10-30T00:00:00Z', created_at: null });
    const wrapper = await mountWith(TeamView, '/team');
    await flushPromises();

    const picker = wrapper.get('[data-permission-picker="invite"]');
    expect(picker.findAll('[data-preset]').map((button) => button.attributes('data-preset'))).toEqual(['custom']);
    expect(picker.get('[data-permission="reconciliation.read"]').attributes('disabled')).toBeDefined();
    expect(picker.get('[data-permission="incidents.read"]').attributes('disabled')).toBeUndefined();
    expect(wrapper.find('[data-invitation="small@example.test"] [data-edit-invitation]').exists()).toBe(true);
    expect(wrapper.find('[data-invitation="big@example.test"] [data-edit-invitation]').exists()).toBe(false);

    await picker.get('[data-permission="incidents.read"]').setValue(true);
    await wrapper.get('[data-panel="invite"] input[type="email"]').setValue('new@example.test');
    await wrapper.get('[data-panel="invite"] form').trigger('submit');
    await flushPromises();
    expect(mocked.inviteMember).toHaveBeenCalledWith('new@example.test', ['stores.read', 'incidents.read']);
  });

  it('removes a member only after confirmation', async () => {
    session.value = sessionFor('owner');
    mocked.listMembers.mockResolvedValue(team(members, 'owner'));
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

describe('ownership transfer', () => {
  const owners = [
    { user_id: 'u-me', tenant_id: 't-1', name: 'Me', salutation: null, first_name: null, last_name: 'Me', company_name: null, email: 'me@example.test', email_verified: true, mfa_enabled: true, role: 'owner' as const, is_you: true, created_at: null },
    { user_id: 'u-anna', tenant_id: 't-1', name: 'Anna', salutation: null, first_name: null, last_name: 'Anna', company_name: null, email: 'anna@example.test', email_verified: true, mfa_enabled: true, role: 'admin' as const, is_you: false, created_at: null },
    { user_id: 'u-new', tenant_id: 't-1', name: 'Neu', salutation: null, first_name: null, last_name: 'Neu', company_name: null, email: 'new@example.test', email_verified: false, mfa_enabled: false, role: 'viewer' as const, is_you: false, created_at: null },
  ];

  it('is offered only to the owner and asks for the password and, with MFA on, a code', async () => {
    session.value = { ...sessionFor('owner'), user: { ...sessionFor('owner').user, mfa_enabled: true } };
    mocked.listMembers.mockResolvedValue(team(owners, 'owner'));
    mocked.listInvitations.mockResolvedValue([]);
    mocked.transferOwnership.mockRejectedValueOnce(new ApiError(422, 'validation_failed', { code: ['mfa_code_invalid'] })).mockResolvedValueOnce();
    const wrapper = await mountWith(TeamView, '/team');
    await flushPromises();

    expect(wrapper.get('[data-member="me@example.test"]').text()).not.toContain('Передать владение');
    await wrapper.get('[data-member="new@example.test"]').findAll('button').find((button) => button.text() === 'Передать владение')!.trigger('click');
    expect(wrapper.get('[data-transfer]').text()).toContain('ещё не подтвердил адрес');
    expect(wrapper.find('[data-transfer] button[type="submit"]').exists()).toBe(false);

    await wrapper.get('[data-member="anna@example.test"]').findAll('button').find((button) => button.text() === 'Передать владение')!.trigger('click');
    const form = wrapper.get('[data-member="anna@example.test"] [data-transfer]');
    const [password, code] = form.findAll('input');
    await password!.setValue('very-secure-password');
    await code!.setValue('123456');
    await form.trigger('submit');
    await flushPromises();
    expect(mocked.transferOwnership).toHaveBeenLastCalledWith('u-anna', 'very-secure-password', '123456');
    expect(wrapper.text()).toContain('Код не подошёл');

    await password!.setValue('very-secure-password');
    await code!.setValue('654321');
    await form.trigger('submit');
    await flushPromises();
    expect(mocked.transferOwnership).toHaveBeenCalledTimes(2);
    expect(mocked.listMembers).toHaveBeenCalledTimes(2);
  });

  it('is not offered to an admin', async () => {
    session.value = sessionFor('admin');
    mocked.listMembers.mockResolvedValue(team(owners.map((item) => ({ ...item, is_you: item.user_id === 'u-anna' })), 'admin'));
    mocked.listInvitations.mockResolvedValue([]);
    const wrapper = await mountWith(TeamView, '/team');
    await flushPromises();

    expect(wrapper.text()).not.toContain('Передать владение');
  });
});

describe('team MFA reset', () => {
  it('lets the owner reset a member with a reason and step-up, then refreshes the team', async () => {
    session.value = { ...sessionFor('owner'), user: { ...sessionFor('owner').user, mfa_enabled: true } };
    const anna = { user_id: 'u-anna', tenant_id: 't-1', name: 'Anna', salutation: null, first_name: null, last_name: 'Anna', company_name: null, email: 'anna@example.test', role: 'admin' as const, mfa_enabled: true, is_you: false, created_at: null };
    mocked.listMembers.mockResolvedValueOnce(team([anna], 'owner'))
      .mockResolvedValueOnce(team([{ ...anna, mfa_enabled: false }], 'owner'));
    mocked.listInvitations.mockResolvedValue([]);
    mocked.resetMemberMfa.mockResolvedValue();
    const wrapper = await mountWith(TeamView, '/team');
    await flushPromises();

    await wrapper.get('[data-member="anna@example.test"]').findAll('button').find((button) => button.text() === 'Сбросить MFA')!.trigger('click');
    const form = wrapper.get('[data-mfa-reset]');
    const inputs = form.findAll('input');
    await inputs[0]!.setValue('Lost phone');
    await inputs[1]!.setValue('very-secure-password');
    await inputs[2]!.setValue('123456');
    await form.trigger('submit');
    await flushPromises();

    expect(mocked.resetMemberMfa).toHaveBeenCalledWith('u-anna', 'Lost phone', 'very-secure-password', '123456');
    expect(wrapper.text()).toContain('MFA сброшена');
    expect(wrapper.find('[data-mfa-reset]').exists()).toBe(false);
  });
});

describe('MfaPanel', () => {
  it('recommends MFA to every team role without requiring it', async () => {
    mocked.fetchMfa.mockResolvedValue({ enabled: false, recovery_codes_remaining: 0 });

    for (const role of ['admin', 'operator', 'viewer'] as const) {
      session.value = sessionFor(role);
      const wrapper = await mountWith(MfaPanel, '/profile');
      await flushPromises();

      expect(wrapper.get('[data-member-hint]').text()).toContain('Это рекомендация, а не условие работы.');
      expect(wrapper.find('[data-owner-hint]').exists()).toBe(false);
      expect(wrapper.text()).toContain('Включить');
      wrapper.unmount();
    }

    mocked.fetchMfa.mockResolvedValue({ enabled: true, recovery_codes_remaining: 10 });
    session.value = sessionFor('admin');
    const protectedPanel = await mountWith(MfaPanel, '/profile');
    await flushPromises();
    expect(protectedPanel.find('[data-member-hint]').exists()).toBe(false);
    protectedPanel.unmount();
  });

  it('enrolls with the password, a QR code and a confirming code, then shows the recovery codes once', async () => {
    session.value = sessionFor('owner');
    mocked.fetchMfa.mockResolvedValue({ enabled: false, recovery_codes_remaining: 0 });
    mocked.beginMfaSetup.mockResolvedValue({ secret: 'JBSWY3DPEHPK3PXP', otpauth_uri: 'otpauth://totp/x', qr_svg: '<svg xmlns="http://www.w3.org/2000/svg"></svg>' });
    mocked.confirmMfa.mockResolvedValue({ enabled: true, recovery_codes_remaining: 10, recovery_codes: ['abcde-fghjk', 'mnpqr-stuvw'] });
    const wrapper = await mountWith(MfaPanel, '/profile');
    await flushPromises();

    expect(wrapper.find('[data-owner-hint]').exists()).toBe(true);
    await wrapper.findAll('button').find((button) => button.text() === 'Включить')!.trigger('click');
    await wrapper.get('#mfa-password').setValue('very-secure-password');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocked.beginMfaSetup).toHaveBeenCalledWith('very-secure-password');
    expect(wrapper.get('[data-qr]').attributes('src')).toMatch(/^data:image\/svg\+xml;base64,/);
    expect((wrapper.get('#mfa-secret').element as HTMLInputElement).value).toBe('JBSWY3DPEHPK3PXP');

    await wrapper.get('#mfa-code').setValue('123456');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocked.confirmMfa).toHaveBeenCalledWith('123456');
    expect(wrapper.get('[data-recovery-codes]').text()).toContain('abcde-fghjk');

    await wrapper.findAll('button').find((button) => button.text() === 'Я сохранил коды')!.trigger('click');
    expect(wrapper.find('[data-recovery-codes]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Неиспользованных резервных кодов: 10.');
  });

  it('turns off only with the password and a code and explains a wrong password', async () => {
    session.value = sessionFor('admin');
    mocked.fetchMfa.mockResolvedValue({ enabled: true, recovery_codes_remaining: 2 });
    mocked.disableMfa.mockRejectedValueOnce(new ApiError(422, 'validation_failed', { current_password: ['current_password_invalid'] })).mockResolvedValueOnce({ enabled: false, recovery_codes_remaining: 0 });
    const wrapper = await mountWith(MfaPanel, '/profile');
    await flushPromises();

    expect(wrapper.find('[data-owner-hint]').exists()).toBe(false);
    await wrapper.findAll('button').find((button) => button.text() === 'Отключить')!.trigger('click');
    await wrapper.get('#mfa-stepup-password').setValue('wrong-password');
    await wrapper.get('#mfa-stepup-code').setValue('abcde-fghjk');
    await wrapper.get('[data-step-up="disable"]').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Текущий пароль указан неверно.');

    await wrapper.get('#mfa-stepup-password').setValue('very-secure-password');
    await wrapper.get('[data-step-up="disable"]').trigger('submit');
    await flushPromises();
    expect(mocked.disableMfa).toHaveBeenLastCalledWith('very-secure-password', 'abcde-fghjk');
    expect(wrapper.text()).toContain('выключена');
  });
});

describe('EmailChangePanel', () => {
  it('asks for the password and, with MFA, a code, then shows the pending address with resend and cancel', async () => {
    session.value = { ...sessionFor('owner'), user: { ...sessionFor('owner').user, mfa_enabled: true } };
    mocked.requestEmailChange.mockImplementation(async () => {
      session.value = { ...session.value!, user: { ...session.value!.user, pending_email_change: { email: 'new@example.test', expires_at: '2026-10-27T10:00:00Z' } } };

      return { email: 'new@example.test', expires_at: '2026-10-27T10:00:00Z' };
    });
    mocked.resendEmailChange.mockResolvedValue({ email: 'new@example.test', expires_at: '2026-10-27T10:00:00Z' });
    mocked.cancelEmailChange.mockImplementation(async () => {
      session.value = { ...session.value!, user: { ...session.value!.user, pending_email_change: null } };
    });
    const wrapper = await mountWith(EmailChangePanel, '/profile');

    await wrapper.get('[data-email-change-open]').trigger('click');
    const form = wrapper.get('[data-email-change-form]');
    expect(form.text()).toContain('каналах уведомлений и у Paddle не меняются');
    const [email, password, code] = form.findAll('input');
    await email!.setValue(' new@example.test ');
    await password!.setValue('very-secure-password');
    expect(form.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    await code!.setValue('123456');
    await form.trigger('submit');
    await flushPromises();

    expect(mocked.requestEmailChange).toHaveBeenCalledWith('new@example.test', 'very-secure-password', '123456');
    expect(wrapper.get('[data-email-change-pending]').text()).toContain('Ожидает подтверждения: new@example.test');
    await wrapper.get('[data-email-change-resend]').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Письмо отправлено.');
    await wrapper.get('[data-email-change-cancel]').trigger('click');
    await flushPromises();
    expect(mocked.cancelEmailChange).toHaveBeenCalledOnce();
    expect(wrapper.find('[data-email-change-pending]').exists()).toBe(false);
  });

  it('explains the result of the links from the old and the new address on the sign-in page', async () => {
    session.value = null;
    expect((await mountWith(LoginView, '/login?email_changed=1')).text()).toContain('Адрес e-mail изменён');
    expect((await mountWith(LoginView, '/login?email_change_cancelled=1')).text()).toContain('Смена адреса отменена');
    expect((await mountWith(LoginView, '/login?email_changed=0')).text()).toContain('Ссылка недействительна или устарела');
  });
});

describe('LoginView', () => {
  it('offers the sign-up when the sign-in finishes a WordPress connection', async () => {
    const wrapper = await mountWith(LoginView, '/login?redirect=/connect');
    expect(wrapper.get('[data-plugin-connect]').text()).toContain('Войдите, чтобы закончить подключение WordPress.');
    expect(wrapper.get('[data-plugin-connect] a').attributes('href')).toBe('/ru/signup?utm_source=wordpress_plugin&utm_medium=connect');
    expect((await mountWith(LoginView, '/login')).find('[data-plugin-connect]').exists()).toBe(false);
  });

  it('sends device trust only after the user opts in during the MFA challenge', async () => {
    session.value = null;
    signIn.mockResolvedValue('mfa');
    completeMfa.mockResolvedValue(sessionFor('owner'));
    const wrapper = await mountWith(LoginView, '/login');
    await wrapper.get('#login-email').setValue('me@example.test');
    await wrapper.get('#login-password').setValue('very-secure-password');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await wrapper.get('#login-code').setValue('123456');
    await wrapper.get('input[type="checkbox"]').setValue(true);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(completeMfa).toHaveBeenCalledWith('123456', true);
  });
  it('offers passkey sign-in when the browser supports it', async () => {
    session.value = null;
    signInWithPasskey.mockResolvedValue(sessionFor('owner'));
    const wrapper = await mountWith(LoginView, '/login');
    await flushPromises();

    await wrapper.findAll('button').find((button) => button.text() === 'Войти с ключом доступа')!.trigger('click');
    await flushPromises();
    expect(signInWithPasskey).toHaveBeenCalledWith(false);
  });

  it('asks for the second factor after a correct password and returns to the password when it expires', async () => {
    session.value = null;
    signIn.mockResolvedValue('mfa');
    completeMfa.mockRejectedValueOnce(new ApiError(422, 'mfa_code_invalid')).mockRejectedValueOnce(new ApiError(401, 'mfa_challenge_expired'));
    const wrapper = await mountWith(LoginView, '/login');

    await wrapper.get('#login-email').setValue('me@example.test');
    await wrapper.get('#login-password').setValue('very-secure-password');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.find('[data-step="mfa"]').exists()).toBe(true);

    await wrapper.get('#login-code').setValue('000000');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('Код не подошёл');

    await wrapper.get('#login-code').setValue('111111');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.find('[data-step="mfa"]').exists()).toBe(false);
    expect(wrapper.text()).toContain('Время на ввод кода истекло');
  });
});

describe('InvitationView', () => {
  const lookup = { tenant_name: 'Kaffee GmbH', email: 'new@example.test', role: 'operator' as const, permissions: permissionsFor('operator'), expires_at: '2026-10-17T10:00:00Z', account_exists: false };

  it('lets a new person create an account for the invited address', async () => {
    session.value = null;
    mocked.lookupInvitation.mockResolvedValue(lookup);
    mocked.registerWithInvitation.mockResolvedValue(sessionFor('viewer', 'new@example.test'));
    const wrapper = await mountWith(InvitationView, '/invitation?token=abc');
    await flushPromises();

    expect(wrapper.text()).toContain('«Kaffee GmbH» с ролью «оператор»');
    expect(wrapper.get('#invite-salutation').findAll('option').map((option) => option.text())).toEqual(['Без обращения', 'Господин', 'Госпожа', 'Другое']);
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    await wrapper.get('#invite-salutation').setValue('ms');
    await wrapper.get('#invite-first-name').setValue('Nina');
    await wrapper.get('#invite-last-name').setValue(' Neu ');
    await wrapper.get('#invite-password').setValue('a-long-enough-password');
    await wrapper.get('#invite-confirmation').setValue('a-long-enough-password');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(mocked.registerWithInvitation).toHaveBeenCalledWith({ salutation: 'ms', first_name: 'Nina', last_name: 'Neu', company_name: null, token: 'abc', email: 'new@example.test', password: 'a-long-enough-password', locale: 'ru' });
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
