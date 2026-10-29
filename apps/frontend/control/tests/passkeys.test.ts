import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@bw/api-client';
import * as passkeys from '@/api/passkeys';
import StaffPasskeysPanel from '@/components/StaffPasskeysPanel.vue';
import LoginView from '@/views/LoginView.vue';
import { localization } from '@/i18n';
import { useStaff } from '@/composables/useStaff';
import { createMemoryHistory, createRouter } from 'vue-router';

vi.mock('@/api/passkeys', () => ({
  passkeysSupported: vi.fn(() => true), passkeyCancelled: vi.fn((e: Error) => e.name === 'NotAllowedError'),
  listStaffPasskeys: vi.fn(async () => []), addStaffPasskey: vi.fn(), deleteStaffPasskey: vi.fn(), signInWithPasskey: vi.fn(),
}));
const mocked = vi.mocked(passkeys);

beforeEach(() => {
  vi.clearAllMocks();
  mocked.passkeysSupported.mockReturnValue(true);
  mocked.listStaffPasskeys.mockResolvedValue([]);
  localization.setLocale('ru');
  useStaff().forget();
});

async function panel() {
  const wrapper = mount(StaffPasskeysPanel, { global: { plugins: [localization] } });
  await flushPromises();
  return wrapper;
}
async function credentials(wrapper: Awaited<ReturnType<typeof panel>>) {
  await wrapper.get('#passkey-password').setValue('private-password');
  await wrapper.get('#passkey-code').setValue('123456');
}
async function login() {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/owner/login', component: LoginView }, { path: '/owner/profile', component: { template: '<p>Profile</p>' } }] });
  await router.push('/owner/login?redirect=/owner/profile');
  const wrapper = mount(LoginView, { global: { plugins: [localization, router] } });
  await flushPromises();
  return { wrapper, router };
}

describe('staff passkeys', () => {
  it('requires a device name, password and MFA to enroll, refreshes the list and clears secrets', async () => {
    const wrapper = await panel();
    await wrapper.get('#passkey-name').setValue('MacBook');
    await wrapper.get('#passkey-password').setValue('private-password');
    expect(wrapper.get('button[type="submit"]').attributes('disabled')).toBeDefined();
    await wrapper.get('#passkey-code').setValue('123456');
    mocked.listStaffPasskeys.mockResolvedValue([{ id: 5, name: 'MacBook', created_at: null, last_used_at: null }]);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocked.addStaffPasskey).toHaveBeenCalledWith('MacBook', 'private-password', '123456');
    expect(wrapper.text()).toContain('MacBook');
    expect((wrapper.get('#passkey-password').element as HTMLInputElement).value).toBe('');
    expect((wrapper.get('#passkey-code').element as HTMLInputElement).value).toBe('');
  });

  it('keeps existing keys visible on unsupported browsers and confirms deletion with fresh MFA', async () => {
    mocked.passkeysSupported.mockReturnValue(false);
    mocked.listStaffPasskeys.mockResolvedValue([{ id: 5, name: 'MacBook', created_at: null, last_used_at: '2026-10-28T10:00:00Z' }]);
    const wrapper = await panel();
    expect(wrapper.text()).toContain('не поддерживает');
    expect(wrapper.find('button[type="submit"]').exists()).toBe(false);
    await wrapper.get('li button').trigger('click');
    expect(wrapper.get('li button').attributes('disabled')).toBeDefined();
    await credentials(wrapper);
    mocked.listStaffPasskeys.mockResolvedValue([]);
    await wrapper.get('li button').trigger('click');
    await flushPromises();
    expect(mocked.deleteStaffPasskey).toHaveBeenCalledWith(5, 'private-password', '123456');
    expect(wrapper.find('li').exists()).toBe(false);
  });

  it('clears secrets after a cancelled enrollment and explains a failed step-up', async () => {
    const wrapper = await panel();
    await wrapper.get('#passkey-name').setValue('MacBook');
    await credentials(wrapper);
    mocked.addStaffPasskey.mockRejectedValueOnce(new DOMException('Cancelled', 'NotAllowedError'));
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    expect((wrapper.get('#passkey-password').element as HTMLInputElement).value).toBe('');
    await credentials(wrapper);
    mocked.addStaffPasskey.mockRejectedValueOnce(new ApiError(422, 'passkey_step_up_failed'));
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Проверьте текущий пароль');
  });

  it('starts the staff session after passkey login and respects the requested page', async () => {
    const { wrapper, router } = await login();
    const staff = { staff_id: 'staff-1' } as Awaited<ReturnType<typeof passkeys.signInWithPasskey>>;
    mocked.signInWithPasskey.mockResolvedValue(staff);
    await wrapper.get('[data-passkey-login]').trigger('click');
    await flushPromises();
    expect(useStaff().me.value?.staff_id).toBe('staff-1');
    expect(router.currentRoute.value.path).toBe('/owner/profile');
  });

  it('retains password login after cancellation or failure and hides passkeys when unsupported', async () => {
    const { wrapper } = await login();
    mocked.signInWithPasskey.mockRejectedValueOnce(new DOMException('Cancelled', 'NotAllowedError'));
    await wrapper.get('[data-passkey-login]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[role="alert"]').exists()).toBe(false);
    mocked.signInWithPasskey.mockRejectedValueOnce(new ApiError(422, 'passkey_invalid'));
    await wrapper.get('[data-passkey-login]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('Не удалось войти');
    expect(wrapper.find('#staff-password').exists()).toBe(true);
    expect(useStaff().me.value).toBeNull();
    mocked.passkeysSupported.mockReturnValue(false);
    expect((await login()).wrapper.find('[data-passkey-login]').exists()).toBe(false);
  });
});
