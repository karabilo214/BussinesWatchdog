import { translationProblems } from '@bw/i18n/testing';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@bw/api-client';
import * as api from '@/api';
import type { StaffMe } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';
import { buildRouter } from '@/router';

vi.mock('@/api', () => ({ fetchMe: vi.fn(), login: vi.fn(), submitMfa: vi.fn(), logout: vi.fn() }));

const mocked = vi.mocked(api);

const OWNER: StaffMe = {
  staff_id: 's-1',
  name: 'Founder',
  first_name: null,
  last_name: 'Founder',
  photo_url: null,
  email: 'founder@example.test',
  locale: 'ru',
  role: 'platform_owner',
  mfa_enabled: true,
  capabilities: ['plans.manage'],
  billing_mode: 'test',
  session: { idle_minutes: 30, absolute_hours: 8 },
};

async function mountAt(path: string) {
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

describe('control app', () => {
  it('has complete translations', () => {
    expect(translationProblems({ en, ru, de })).toEqual([]);
  });

  it('sends a visitor without a staff session to the sign-in page', async () => {
    mocked.fetchMe.mockRejectedValue(new ApiError(401, 'platform_unauthenticated'));
    useStaff().forget();
    await useStaff().load(true);
    const { router } = await mountAt('/owner/plans');

    expect(router.currentRoute.value.name).toBe('login');
    expect(router.currentRoute.value.query.redirect).toBe('/owner/plans');
  });

  it('signs in with password and authenticator code, then shows the panel with the payment mode', async () => {
    mocked.fetchMe.mockRejectedValue(new ApiError(401, 'platform_unauthenticated'));
    await useStaff().load(true);
    mocked.login.mockResolvedValue();
    mocked.submitMfa.mockResolvedValue(OWNER);
    const { wrapper, router } = await mountAt('/owner/login?redirect=/owner/plans');

    await wrapper.get('#staff-email').setValue('founder@example.test');
    await wrapper.get('#staff-password').setValue('founder-password-1234');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocked.login).toHaveBeenCalledWith('founder@example.test', 'founder-password-1234');

    await wrapper.get('#staff-code').setValue('123456');
    await wrapper.get('[data-step="mfa"]').trigger('submit');
    await flushPromises();

    await vi.waitFor(() => expect(router.currentRoute.value.fullPath).toBe('/owner/plans'));
    await vi.waitFor(() => expect(wrapper.get('h1').text()).toBe('Тарифы и скидки'));
    expect(wrapper.get('[data-mode]').text()).toContain('TEST');
    expect(wrapper.get('[data-staff]').text()).toContain('владелец сервиса');
  });

  it('explains a wrong code and a missing authenticator', async () => {
    mocked.fetchMe.mockRejectedValue(new ApiError(401, 'platform_unauthenticated'));
    await useStaff().load(true);
    mocked.login.mockRejectedValueOnce(new ApiError(403, 'platform_mfa_enrollment_required'));
    const { wrapper } = await mountAt('/owner/login');

    await wrapper.get('#staff-email').setValue('founder@example.test');
    await wrapper.get('#staff-password').setValue('founder-password-1234');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('командой на сервере');

    mocked.login.mockResolvedValue();
    mocked.submitMfa.mockRejectedValue(new ApiError(422, 'mfa_code_invalid'));
    await wrapper.get('#staff-password').setValue('founder-password-1234');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    await wrapper.get('#staff-code').setValue('000000');
    await wrapper.get('[data-step="mfa"]').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toBe('Код не подошёл. Попробуйте ещё раз.');
  });
});
