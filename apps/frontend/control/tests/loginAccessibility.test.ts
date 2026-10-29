import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import { localization } from '@/i18n';
import LoginView from '@/views/LoginView.vue';
const mocks = vi.hoisted(() => ({ signIn: vi.fn(), completeMfa: vi.fn(), passkey: vi.fn() }));
vi.mock('@/composables/useStaff', () => ({ useStaff: () => ({ signIn: mocks.signIn, completeMfa: mocks.completeMfa, acceptSession: vi.fn() }) }));
vi.mock('@/api/passkeys', () => ({ passkeysSupported: () => true, signInWithPasskey: mocks.passkey, passkeyCancelled: (error: Error) => error.name === 'Cancelled' }));
const mounted: ReturnType<typeof mount>[] = [];
afterEach(() => { for (const wrapper of mounted.splice(0)) wrapper.unmount(); document.body.innerHTML = ''; });
beforeEach(() => { vi.resetAllMocks(); });
async function view() {
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/login', component: LoginView },
    { path: '/home', name: 'owner-overview', component: { template: '<div />' } },
    { path: '/forgot', name: 'forgot-password', component: { template: '<div />' } },
  ] });
  await router.push('/login');
  const wrapper = mount(LoginView, { attachTo: document.body, global: { plugins: [localization, router] } });
  mounted.push(wrapper);
  await flushPromises();
  return wrapper;
}
async function credentials(wrapper: ReturnType<typeof mount>) {
  await wrapper.get('input[name=email]').setValue('synthetic@example.test');
  await wrapper.get('input[name=password]').setValue('Synthetic-password-123');
}
describe('Sign-in accessibility and recovery', () => {
  it('explains empty fields, focuses the summary and links back to each input', async () => {
    const wrapper = await view();
    expect(wrapper.get('button[type=submit]').attributes('disabled')).toBeUndefined();
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocks.signIn).not.toHaveBeenCalled();
    expect(document.activeElement?.id).toBe('auth-errors');
    expect(wrapper.findAll('[aria-invalid=true]')).toHaveLength(2);
    await wrapper.get('#auth-errors a').trigger('click');
    expect((document.activeElement as HTMLInputElement).name).toBe('email');
  });
  it.each(['Autofilled-password-123', '            '])('reads native autofill without trimming the password (%s)', async (password) => {
    const wrapper = await view();
    (wrapper.get('input[name=email]').element as HTMLInputElement).value = 'autofill@example.test';
    (wrapper.get('input[name=password]').element as HTMLInputElement).value = password;
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocks.signIn.mock.calls[0]?.slice(0, 2)).toEqual(['autofill@example.test', password]);
  });
  it('rejects an invalid email locally and keeps the password manager semantics when revealed', async () => {
    const wrapper = await view();
    await credentials(wrapper);
    await wrapper.get('input[name=email]').setValue('broken');
    const toggle = wrapper.get('[aria-controls$="password"]');
    await toggle.trigger('click');
    expect(wrapper.get('input[name=password]').attributes('type')).toBe('text');
    expect(wrapper.get('input[name=password]').attributes('autocomplete')).toBe('current-password');
    expect(toggle.attributes('aria-pressed')).toBe('true');
    await wrapper.get('form').trigger('submit');
    expect(mocks.signIn).not.toHaveBeenCalled();
    expect(wrapper.get('input[name=email]').attributes('aria-invalid')).toBe('true');
  });
  it('prevents duplicate requests and restores a retry after a network error without retaining the password', async () => {
    const wrapper = await view();
    let reject!: (reason: unknown) => void;
    mocks.signIn.mockImplementation(() => new Promise((_, fail) => { reject = fail; }));
    await credentials(wrapper);
    await wrapper.get('form').trigger('submit');
    await wrapper.get('form').trigger('submit');
    expect(mocks.signIn).toHaveBeenCalledTimes(1);
    expect(wrapper.get('form').attributes('aria-busy')).toBe('true');
    reject(new ApiError(0, 'network'));
    await flushPromises();
    expect(document.activeElement?.id).toBe('auth-errors');
    expect((wrapper.get('input[name=password]').element as HTMLInputElement).value).toBe('');
    expect(wrapper.get('button[type=submit]').attributes('disabled')).toBeUndefined();
  });
  it('focuses MFA, accepts native code autofill, and focuses the password when going back', async () => {
    const wrapper = await view();
    mocks.signIn.mockResolvedValue(undefined);
    await credentials(wrapper);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect((document.activeElement as HTMLInputElement).name).toBe('code');
    (wrapper.get('input[name=code]').element as HTMLInputElement).value = '123456';
    mocks.completeMfa.mockRejectedValue(new Error('retry'));
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocks.completeMfa.mock.calls[0]?.[0]).toBe('123456');
    expect(document.activeElement?.id).toBe('auth-errors');
    await wrapper.get('button[type=button]').trigger('click');
    await flushPromises();
    expect((document.activeElement as HTMLInputElement).name).toBe('password');
  });
  it('restores focus to the passkey button when the user cancels', async () => {
    const wrapper = await view();
    await credentials(wrapper);
    mocks.passkey.mockRejectedValue(Object.assign(new Error('cancelled'), { name: 'Cancelled' }));
    const button = wrapper.get('button[type=button]:not([aria-controls])');
    await button.trigger('click');
    await flushPromises();
    expect(document.activeElement).toBe(button.element);
    expect(wrapper.find('#auth-errors').exists()).toBe(false);
    expect((wrapper.get('input[name=password]').element as HTMLInputElement).value).toBe('');
  });

});
