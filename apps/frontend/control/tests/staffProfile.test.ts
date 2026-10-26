import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import * as api from '@/api';
import type { StaffCatalog, StaffMe } from '@/api';
import App from '@/App.vue';
import SelfieCamera from '@/components/SelfieCamera.vue';
import StaffProfileEditor from '@/components/StaffProfileEditor.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';
import ResetPasswordView from '@/views/ResetPasswordView.vue';

vi.mock('@/api', () => ({
  fetchMe: vi.fn(),
  logout: vi.fn(),
  fetchStaff: vi.fn(),
  fetchOwnerAttention: vi.fn(async () => ({})),
  updateStaffProfile: vi.fn(),
  uploadStaffPhoto: vi.fn(),
  removeStaffPhoto: vi.fn(),
  changeOwnPassword: vi.fn(),
  sendStaffPasswordLink: vi.fn(),
  completeStaffPasswordReset: vi.fn(),
}));

const mocked = vi.mocked(api);
const profile = { id: 's-2', name: 'Mia Ops', first_name: 'Mia', last_name: 'Ops', photo_url: '/platform-api/v1/staff/s-2/photo?v=1', can_edit: true };

function me(role: StaffMe['role'], capabilities: string[], id = 's-1'): StaffMe {
  return { staff_id: id, name: 'Anna Founder', first_name: 'Anna', last_name: 'Founder', photo_url: null, email: 'f@example.test', locale: 'ru', role, mfa_enabled: true, capabilities, billing_mode: 'live', session: { idle_minutes: 30, absolute_hours: 8 } };
}

const CATALOG: StaffCatalog = {
  staff: [
    { id: 's-1', name: 'Anna Founder', first_name: 'Anna', last_name: 'Founder', photo_url: null, email: 'f@example.test', role: 'platform_owner', capabilities: ['staff.manage'], state: 'active', mfa_enabled: true, created_at: null, disabled_at: null, last_login_at: null },
    { id: 's-2', name: 'Mia Ops', first_name: 'Mia', last_name: 'Ops', photo_url: '/platform-api/v1/staff/s-2/photo?v=1', email: 'ops@example.test', role: 'platform_member', capabilities: ['tenants.read'], state: 'active', mfa_enabled: true, created_at: null, disabled_at: null, last_login_at: null },
  ],
  invitations: [],
  capability_catalog: [{ code: 'staff.manage', owner_only: true }, { code: 'tenants.read', owner_only: false }],
  presets: {},
  implies: {},
};

async function openApp(path: string, current: StaffMe) {
  mocked.fetchMe.mockResolvedValue(current);
  await useStaff().load(true);
  const router = buildRouter();
  await router.push(path);
  await router.isReady();
  const wrapper = mount(App, { global: { plugins: [localization, router] } });
  await flushPromises();

  return wrapper;
}

function editor(self: boolean) {
  return mount(StaffProfileEditor, { props: { staffId: 's-2', firstName: 'Mia', lastName: 'Ops', name: 'Mia Ops', photoUrl: null, self }, global: { plugins: [localization] } });
}

beforeEach(() => {
  vi.clearAllMocks();
  useStaff().forget();
  localization.setLocale('ru');
});

describe('staff profiles in the owner panel', () => {
  it('shows the own photo in the header linking to "My profile" and offers profile editing only where allowed', async () => {
    mocked.fetchStaff.mockResolvedValue(CATALOG);
    const owner = await openApp('/owner/staff', me('platform_owner', ['staff.manage']));

    expect(owner.get('[data-staff]').attributes('href')).toBe('/owner/profile');
    expect(owner.get('[data-staff] [data-avatar]').attributes('data-avatar')).toBe('initials');
    expect(owner.get('[data-staff="ops@example.test"] [data-avatar]').attributes('src')).toBe('/platform-api/v1/staff/s-2/photo?v=1');
    await owner.get('[data-staff="ops@example.test"] [data-action="profile"]').trigger('click');
    expect(owner.get('[data-staff-profile]').text()).toContain('Профиль: Mia Ops');
    expect(owner.find('[data-staff-profile] [data-profile-password-link]').exists()).toBe(true);
    expect(owner.find('[data-staff-profile] [data-profile-password]').exists()).toBe(false);

    const member = await openApp('/owner/profile', me('platform_member', ['tenants.read'], 's-2'));
    expect(member.get('h1').text()).toBe('Мой профиль');
    expect(member.find('[data-profile-password]').exists()).toBe(true);
  });

  it('saves the name, uploads a chosen file and removes the photo', async () => {
    mocked.updateStaffProfile.mockResolvedValue({ ...profile, first_name: null, last_name: 'Neu', name: 'Neu' });
    mocked.uploadStaffPhoto.mockResolvedValue(profile);
    mocked.removeStaffPhoto.mockResolvedValue({ ...profile, photo_url: null });
    const wrapper = editor(false);

    await wrapper.get('[data-field="first-name"]').setValue('  ');
    await wrapper.get('[data-field="last-name"]').setValue(' Neu ');
    await wrapper.get('[data-profile-name]').trigger('submit');
    await flushPromises();
    expect(mocked.updateStaffProfile).toHaveBeenCalledWith('s-2', { first_name: null, last_name: 'Neu' });

    const file = new File(['x'], 'me.png', { type: 'image/png' });
    const input = wrapper.get('[data-photo-file]');
    Object.defineProperty(input.element, 'files', { value: [file] });
    await input.trigger('change');
    await flushPromises();
    expect(mocked.uploadStaffPhoto).toHaveBeenCalledWith('s-2', file, 'me.png');
    expect(wrapper.get('[data-avatar]').attributes('src')).toBe(profile.photo_url);

    await wrapper.get('[data-photo-remove]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-avatar]').attributes('data-avatar')).toBe('initials');
  });

  it('changes the own password with the code and sends others only a link confirmed by the code', async () => {
    mocked.changeOwnPassword.mockRejectedValueOnce(new ApiError(422, 'current_password_invalid')).mockResolvedValueOnce();
    const own = editor(true);
    await own.get('[data-field="current-password"]').setValue('old-password-123');
    await own.get('[data-field="new-password"]').setValue('a-brand-new-password');
    await own.get('[data-field="repeat-password"]').setValue('a-brand-new-passwor');
    expect(own.text()).toContain('Пароли не совпадают.');
    await own.get('[data-field="repeat-password"]').setValue('a-brand-new-password');
    await own.get('[data-field="password-code"]').setValue('123456');
    await own.get('[data-profile-password]').trigger('submit');
    await flushPromises();
    expect(own.text()).toContain('Текущий пароль неверен.');
    await own.get('[data-profile-password]').trigger('submit');
    await flushPromises();
    expect(mocked.changeOwnPassword).toHaveBeenLastCalledWith({ current_password: 'old-password-123', password: 'a-brand-new-password', confirmation_code: '123456' });
    expect(own.text()).toContain('Пароль изменён');

    mocked.sendStaffPasswordLink.mockResolvedValue();
    const other = editor(false);
    expect(other.text()).toContain('Пароль за другого не задаётся');
    await other.get('[data-field="link-code"]').setValue('654321');
    await other.get('[data-profile-password-link]').trigger('submit');
    await flushPromises();
    expect(mocked.sendStaffPasswordLink).toHaveBeenCalledWith('s-2', '654321');
  });

  it('explains a refused camera and switches the camera off on cancel', async () => {
    const stop = vi.fn();
    const getUserMedia = vi.fn().mockRejectedValueOnce(new DOMException('no', 'NotAllowedError')).mockResolvedValueOnce({ getTracks: () => [{ stop }] });
    Object.defineProperty(window, 'isSecureContext', { value: true, configurable: true });
    Object.defineProperty(navigator, 'mediaDevices', { value: { getUserMedia }, configurable: true });
    HTMLMediaElement.prototype.play = vi.fn(async () => undefined);

    const denied = mount(SelfieCamera, { global: { plugins: [localization] } });
    await flushPromises();
    expect(denied.get('[data-camera-problem]').attributes('data-camera-problem')).toBe('denied');

    const live = mount(SelfieCamera, { global: { plugins: [localization] } });
    await flushPromises();
    expect(getUserMedia).toHaveBeenLastCalledWith({ video: { facingMode: 'user', width: { ideal: 1024 }, height: { ideal: 1024 } }, audio: false });
    await live.get('[data-selfie-cancel]').trigger('click');
    expect(stop).toHaveBeenCalledOnce();
    expect(live.emitted('cancel')).toHaveLength(1);
  });
});

describe('ResetPasswordView', () => {
  afterEach(() => vi.clearAllMocks());

  it('sets the password from the one-time link and explains an expired one', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/owner/reset-password', component: ResetPasswordView }, { path: '/owner/login', name: 'login', component: { template: '<div />' } }] });
    await router.push('/owner/reset-password?email=ops%40example.test&token=abc');
    mocked.completeStaffPasswordReset.mockRejectedValueOnce(new ApiError(422, 'password_reset_invalid')).mockResolvedValueOnce();
    const wrapper = mount(ResetPasswordView, { global: { plugins: [localization, router] } });

    expect(wrapper.text()).toContain('ops@example.test');
    await wrapper.get('[data-field="password"]').setValue('my-own-new-password');
    await wrapper.get('[data-field="repeat"]').setValue('my-own-new-password');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[data-reset-error]').text()).toContain('устарела');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocked.completeStaffPasswordReset).toHaveBeenLastCalledWith('ops@example.test', 'abc', 'my-own-new-password');
    expect(wrapper.find('[data-reset-done]').exists()).toBe(true);
  });
});
