import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api';
import type { StaffCatalog, StaffMe } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';

vi.mock('@/api', () => ({
  fetchMe: vi.fn(),
  login: vi.fn(),
  submitMfa: vi.fn(),
  logout: vi.fn(),
  fetchStaff: vi.fn(),
  inviteStaff: vi.fn(),
  revokeStaffInvitation: vi.fn(),
  updateStaff: vi.fn(),
  revokeStaffSessions: vi.fn(),
  lookupStaffInvitation: vi.fn(),
  startStaffInvitation: vi.fn(),
  confirmStaffInvitation: vi.fn(),
}));

const mocked = vi.mocked(api);

const CATALOG: StaffCatalog = {
  staff: [
    { id: 's-1', name: 'Founder', email: 'f@example.test', role: 'platform_owner', state: 'active', mfa_enabled: true, created_at: null, disabled_at: null, last_login_at: '2026-10-20T09:00:00Z' },
    { id: 's-2', name: 'Ops', email: 'ops@example.test', role: 'platform_admin', state: 'active', mfa_enabled: true, created_at: null, disabled_at: null, last_login_at: null },
  ],
  invitations: [{ id: 'i-1', email: 'new@example.test', role: 'platform_support', expires_at: '2026-10-23T10:00:00Z', created_at: null }],
};

function staff(capabilities: string[]): StaffMe {
  return { staff_id: 's-1', name: 'Founder', email: 'f@example.test', locale: 'ru', role: 'platform_owner', mfa_enabled: true, capabilities, billing_mode: 'live', session: { idle_minutes: 30, absolute_hours: 8 } };
}

async function open(path: string, capabilities: string[] | null) {
  if (capabilities === null) mocked.fetchMe.mockRejectedValue(new Error('signed out'));
  else mocked.fetchMe.mockResolvedValue(staff(capabilities));
  await useStaff().load(true).catch(() => null);
  const router = buildRouter();
  await router.push(path);
  await router.isReady();
  const wrapper = mount(App, { global: { plugins: [localization, router] } });
  await flushPromises();

  return { wrapper, router };
}

beforeEach(() => {
  vi.clearAllMocks();
  useStaff().forget();
  localization.setLocale('ru');
});

describe('staff', () => {
  it('lists staff, invites with a code, changes a role and ends sessions, never offering actions on oneself', async () => {
    mocked.fetchStaff.mockResolvedValue(CATALOG);
    mocked.inviteStaff.mockResolvedValue(CATALOG.invitations[0]!);
    mocked.updateStaff.mockResolvedValue({ ...CATALOG, staff: [CATALOG.staff[0]!, { ...CATALOG.staff[1]!, role: 'platform_billing' }] });
    const { wrapper } = await open('/owner/staff', ['staff.manage']);

    expect(wrapper.get('[data-staff="f@example.test"]').text()).toContain('вы');
    expect(wrapper.get('[data-staff="f@example.test"]').find('[data-action="role"]').exists()).toBe(false);
    expect(wrapper.get('[data-invitation="new@example.test"]').text()).toContain('поддержка');

    await wrapper.get('[data-action="invite"]').trigger('click');
    await wrapper.get('[data-field="email"]').setValue('team@example.test');
    await wrapper.get('[data-field="role"]').setValue('platform_admin');
    await wrapper.get('[data-field="reason"]').setValue('New ops');
    expect(wrapper.get('[data-action="send-invite"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="code"]').setValue('123456');
    await wrapper.get('[data-form="invite"]').trigger('submit');
    await flushPromises();
    expect(mocked.inviteStaff).toHaveBeenCalledWith({ email: 'team@example.test', role: 'platform_admin', reason: 'New ops', confirmation_code: '123456' });
    expect(wrapper.get('[data-notice]').text()).toBe('Приглашение отправлено.');

    await wrapper.get('[data-staff="ops@example.test"] [data-action="role"]').trigger('click');
    await wrapper.get('[data-field="new-role"]').setValue('platform_billing');
    await wrapper.get('[data-field="action-reason"]').setValue('Finance now');
    await wrapper.get('[data-field="action-code"]').setValue('654321');
    await wrapper.get('[data-form="role"]').trigger('submit');
    await flushPromises();
    expect(mocked.updateStaff).toHaveBeenCalledWith('s-2', { role: 'platform_billing', reason: 'Finance now', confirmation_code: '654321' });

    await wrapper.get('[data-staff="ops@example.test"] [data-action="sessions"]').trigger('click');
    expect(wrapper.find('[data-field="action-code"]').exists()).toBe(false);
    await wrapper.get('[data-field="action-reason"]').setValue('Lost laptop');
    await wrapper.get('[data-form="sessions"]').trigger('submit');
    await flushPromises();
    expect(mocked.revokeStaffSessions).toHaveBeenCalledWith('s-2', 'Lost laptop');
  });

  it('accepts an invitation: password, authenticator QR and code, recovery codes, link to sign in', async () => {
    mocked.lookupStaffInvitation.mockResolvedValue({ email: 'new@example.test', role: 'platform_support', expires_at: '2026-10-23T10:00:00Z', account_exists: false });
    mocked.startStaffInvitation.mockResolvedValue({ mfa_enabled: false, secret: 'ABCDEF', otpauth_uri: 'otpauth://totp/x', qr_svg: '<svg data-test-qr></svg>' });
    mocked.confirmStaffInvitation.mockResolvedValue({ staff_id: 's-9', recovery_codes: ['aaaa-bbbb', 'cccc-dddd'] });
    const { wrapper } = await open('/owner/staff-invitation?token=tok123', null);

    expect(mocked.lookupStaffInvitation).toHaveBeenCalledWith('tok123');
    expect(wrapper.get('[data-step="account"]').text()).toContain('new@example.test');
    await wrapper.get('[data-field="name"]').setValue('New Person');
    await wrapper.get('[data-field="password"]').setValue('long-password-123');
    await wrapper.get('[data-field="repeat"]').setValue('long-password-12');
    expect(wrapper.get('[data-action="continue"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="repeat"]').setValue('long-password-123');
    await wrapper.get('[data-step="account"]').trigger('submit');
    await flushPromises();
    expect(mocked.startStaffInvitation).toHaveBeenCalledWith({ token: 'tok123', password: 'long-password-123', name: 'New Person' });
    expect(wrapper.get('[data-qr]').html()).toContain('data-test-qr');
    expect(wrapper.get('[data-secret]').text()).toBe('ABCDEF');

    await wrapper.get('[data-field="code"]').setValue('123456');
    await wrapper.get('[data-step="mfa"]').trigger('submit');
    await flushPromises();
    expect(mocked.confirmStaffInvitation).toHaveBeenCalledWith({ token: 'tok123', code: '123456' });
    expect(wrapper.get('[data-recovery]').text()).toContain('cccc-dddd');
    expect(wrapper.get('[data-action="to-login"]').attributes('href')).toBe('/owner/login');
  });

  it('explains a broken invitation link', async () => {
    mocked.lookupStaffInvitation.mockRejectedValue(new Error('invalid'));
    const { wrapper } = await open('/owner/staff-invitation?token=bad', null);

    expect(wrapper.get('[data-step="invalid"]').text()).toContain('Ссылка не работает');
  });
});
