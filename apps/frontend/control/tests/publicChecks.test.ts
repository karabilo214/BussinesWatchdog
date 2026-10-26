import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, expect, it, vi } from 'vitest';
import * as api from '@/api';
import type { StaffMe } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';

vi.mock('@/api', () => ({ fetchMe: vi.fn(), logout: vi.fn(), fetchPublicStorefrontChecks: vi.fn() }));
const mocked = vi.mocked(api);

async function open(capabilities: string[]) {
  const staff: StaffMe = { staff_id: 's-1', name: 'Ops', first_name: null, last_name: 'Ops', photo_url: null, email: 'ops@example.test', locale: 'ru', role: 'platform_member', mfa_enabled: true, capabilities, billing_mode: 'live', session: { idle_minutes: 30, absolute_hours: 8 } };
  mocked.fetchMe.mockResolvedValue(staff);
  await useStaff().load(true);
  const router = buildRouter();
  await router.push('/owner/public-checks');
  await router.isReady();
  const wrapper = mount(App, { global: { plugins: [localization, router] } });
  await flushPromises();
  return wrapper;
}

beforeEach(() => {
  vi.clearAllMocks();
  useStaff().forget();
  localization.setLocale('ru');
});

it('shows public check URL, time, IP, visitor browser and result, then loads another page', async () => {
  mocked.fetchPublicStorefrontChecks
    .mockResolvedValueOnce({ data: [{ id: 'check-1', url: 'https://shop.example.com', created_at: '2026-10-20T10:00:00Z', started_at: null, finished_at: null, ip: '203.0.113.44', user_agent: 'Mozilla/5.0 Chrome/143.0', worker_browser: 'Chromium 143', status: 'passed', verdict: 'checkout_reached', error_code: null }], next_cursor: 'next-page' })
    .mockResolvedValueOnce({ data: [{ id: 'check-2', url: 'https://other.example.com', created_at: '2026-10-20T09:00:00Z', started_at: null, finished_at: null, ip: '203.0.113.45', user_agent: 'Firefox/140.0', worker_browser: null, status: 'blocked', verdict: null, error_code: 'waf_challenge' }], next_cursor: null });
  const wrapper = await open(['service.read']);
  expect(wrapper.get('[data-check="check-1"]').text()).toContain('https://shop.example.com');
  expect(wrapper.get('[data-check="check-1"]').text()).toContain('203.0.113.44');
  expect(wrapper.get('[data-check="check-1"]').text()).toContain('Chrome 143.0');
  expect(wrapper.get('[data-check="check-1"]').text()).toContain('Успешно');
  await wrapper.get('[data-action="more"]').trigger('click');
  await flushPromises();
  expect(mocked.fetchPublicStorefrontChecks).toHaveBeenCalledWith('next-page');
  expect(wrapper.findAll('[data-check]')).toHaveLength(2);
});

it('does not load visitor metadata without service access', async () => {
  const wrapper = await open(['billing.read']);
  expect(mocked.fetchPublicStorefrontChecks).not.toHaveBeenCalled();
  expect(wrapper.text()).toContain('Раздел доступен владельцу и администратору сервиса.');
});
