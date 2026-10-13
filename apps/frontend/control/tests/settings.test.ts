import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api';
import type { PlatformSettingsView, StaffMe } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';

vi.mock('@/api', () => ({ fetchMe: vi.fn(), login: vi.fn(), submitMfa: vi.fn(), logout: vi.fn(), fetchPlatformSettings: vi.fn(), scheduleMaintenance: vi.fn(), cancelMaintenance: vi.fn() }));

const mocked = vi.mocked(api);
const SECRET = { configured: true, fingerprint: 'sha256:abcdef123456' };
const SETTINGS: PlatformSettingsView = {
  configuration: {
    environment: 'production', release: '2026.10.20-1', app_url: 'https://app.watchdog.test',
    billing: { enforcement: 'enforce', paddle_environment: 'production', paddle_account: 'default', api_key: SECRET, client_token: SECRET, webhook_secret: { configured: false, fingerprint: null } },
    mail: { mailer: 'smtp', from: 'watchdog@example.test' },
    telegram: { bot: 'watchdog_bot', token: SECRET, webhook_secret: SECRET },
    sessions: { idle_minutes: 30, absolute_hours: 8 },
    retention: { screenshots_days: 30, exports_days: 7, contact_days: 365 },
    site: { contact_notify: 2, cache_seconds: 60 },
  },
  maintenance: { current: null, history: [], limits: { max_hours: 24, max_days_ahead: 90 } },
};

function staff(capabilities: string[]): StaffMe {
  return { staff_id: 's-1', name: 'Founder', email: 'f@example.test', locale: 'ru', role: 'platform_owner', mfa_enabled: true, capabilities, billing_mode: 'live', session: { idle_minutes: 30, absolute_hours: 8 } };
}

async function open(capabilities: string[]) {
  mocked.fetchMe.mockResolvedValue(staff(capabilities));
  await useStaff().load(true);
  const router = buildRouter();
  await router.push('/owner/settings');
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

describe('settings', () => {
  it('shows configuration with fingerprints only and publishes then cancels maintenance with reason and code', async () => {
    mocked.fetchPlatformSettings.mockResolvedValue(SETTINGS);
    const planned = { id: 'm-1', version: 1, starts_at: '2026-10-21T22:00:00Z', ends_at: '2026-10-22T02:00:00Z', messages: { ru: 'Работы', en: 'Maintenance', de: 'Wartung' }, reason: 'DB upgrade', created_by: 'Founder', created_at: null, cancelled_at: null, cancelled_by: null };
    mocked.scheduleMaintenance.mockResolvedValue({ ...SETTINGS, maintenance: { ...SETTINGS.maintenance, current: planned, history: [planned] } });
    mocked.cancelMaintenance.mockResolvedValue(SETTINGS);
    const wrapper = await open(['settings.manage']);

    expect(wrapper.get('[data-secret="api_key"]').text()).toContain('sha256:abcdef123456');
    expect(wrapper.get('[data-secret="webhook_secret"]').text()).toContain('не задан');
    expect(wrapper.find('[data-none]').exists()).toBe(true);

    await wrapper.get('[data-action="plan"]').trigger('click');
    await wrapper.get('[data-field="starts"]').setValue('2026-10-21T22:00');
    await wrapper.get('[data-field="ends"]').setValue('2026-10-22T02:00');
    await wrapper.get('[data-field="message-ru"]').setValue('Работы');
    await wrapper.get('[data-field="message-en"]').setValue('Maintenance');
    await wrapper.get('[data-field="reason"]').setValue('DB upgrade');
    expect(wrapper.get('[data-action="publish"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="message-de"]').setValue('Wartung');
    await wrapper.get('[data-field="code"]').setValue('123456');
    await wrapper.get('[data-form="maintenance"]').trigger('submit');
    await flushPromises();

    const input = mocked.scheduleMaintenance.mock.calls[0]![0];
    expect(input.messages).toEqual({ ru: 'Работы', en: 'Maintenance', de: 'Wartung' });
    expect(input.confirmation_code).toBe('123456');
    expect(wrapper.get('[data-current]').text()).toContain('версия 1');

    await wrapper.get('[data-action="cancel"]').trigger('click');
    await wrapper.get('[data-field="cancel-reason"]').setValue('Done early');
    await wrapper.get('[data-field="cancel-code"]').setValue('654321');
    await wrapper.get('[data-form="cancel"]').trigger('submit');
    await flushPromises();
    expect(mocked.cancelMaintenance).toHaveBeenCalledWith({ reason: 'Done early', confirmation_code: '654321' });
    expect(wrapper.get('[data-notice]').text()).toBe('Плановые работы отменены.');
  });

  it('does not load settings for staff without access', async () => {
    const wrapper = await open(['service.read']);

    expect(mocked.fetchPlatformSettings).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('Раздел доступен владельцу сервиса.');
  });
});
