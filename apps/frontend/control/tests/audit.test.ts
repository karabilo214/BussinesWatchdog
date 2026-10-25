import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api';
import type { AuditPage, StaffMe } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';

vi.mock('@/api', async () => {
  const actual = await vi.importActual<typeof import('@/api')>('@/api');

  return { fetchMe: vi.fn(), login: vi.fn(), submitMfa: vi.fn(), logout: vi.fn(), fetchAudit: vi.fn(), auditExportUrl: actual.auditExportUrl };
});

const mocked = vi.mocked(api);
const PAGE: AuditPage = {
  data: [
    { id: 'a-2', created_at: '2026-10-20T10:00:00Z', actor: { id: 's-1', name: 'Founder' }, actor_kind: 'staff', action: 'tenant.suspend', target_type: 'tenant', target_id: 't-1', tenant: { id: 't-1', name: 'Kaffee GmbH' }, result: 'succeeded', presentation: { title: 'Сервис приостановлен', result_label: 'Успешно', description: 'Сервис приостановлен · Kaffee GmbH · Причина: Abuse report', object: { label: 'Kaffee GmbH', href: '/owner/tenants/t-1?tab=audit' }, details: ['Причина: Abuse report'], links: [] } },
    { id: 'a-1', created_at: '2026-10-19T10:00:00Z', actor: null, actor_kind: 'system', action: 'staff.mfa_reset', target_type: 'staff', target_id: 's-2', tenant: null, result: 'succeeded', presentation: { title: 'Аутентификатор сотрудника сброшен', result_label: 'Успешно', description: 'Аутентификатор сотрудника сброшен · Удалён', object: { label: 'Удалён', href: null }, details: [], links: [] } },
  ],
  next_cursor: 'a-1',
  actions: ['staff.mfa_reset', 'tenant.suspend'],
  staff: [{ id: 's-1', name: 'Founder' }],
};

function staff(capabilities: string[]): StaffMe {
  return { staff_id: 's-1', name: 'Founder', email: 'f@example.test', locale: 'ru', role: 'platform_owner', mfa_enabled: true, capabilities, billing_mode: 'live', session: { idle_minutes: 30, absolute_hours: 8 } };
}

async function open(path: string, capabilities: string[]) {
  mocked.fetchMe.mockResolvedValue(staff(capabilities));
  await useStaff().load(true);
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

describe('audit', () => {
  it('shows entries with labels, reason and details, filters through the address, pages on and exports the same filters', async () => {
    mocked.fetchAudit.mockResolvedValue(PAGE);
    const { wrapper, router } = await open('/owner/audit', ['audit.read']);

    expect(mocked.fetchAudit).toHaveBeenCalledWith({});
    const suspend = wrapper.get('[data-entry="tenant.suspend"]');
    expect(suspend.text()).toContain('Сервис приостановлен');
    expect(suspend.text()).toContain('Abuse report');
    expect(suspend.get('a').attributes('href')).toBe('/owner/tenants/t-1?tab=audit');
    expect(wrapper.get('[data-entry="staff.mfa_reset"]').text()).toContain('Система');
    await wrapper.get('[data-toggle="a-2"]').trigger('click');
    expect(wrapper.get('[data-details]').text()).toContain('Причина: Abuse report');
    expect(wrapper.text()).not.toContain('req-1');
    expect(wrapper.text()).not.toContain('access_before');

    await wrapper.get('[data-field="action"]').setValue('tenant.suspend');
    await wrapper.get('[data-field="result"]').setValue('denied');
    await wrapper.get('[data-form="audit"]').trigger('submit');
    await flushPromises();
    expect(mocked.fetchAudit).toHaveBeenLastCalledWith({ action: 'tenant.suspend', result: 'denied' });
    expect(router.currentRoute.value.query).toEqual({ action: 'tenant.suspend', result: 'denied' });
    expect(wrapper.get('[data-action="export"]').attributes('href')).toBe('/platform-api/v1/audit/export?action=tenant.suspend&result=denied');

    await wrapper.get('[data-action="more"]').trigger('click');
    await flushPromises();
    expect(mocked.fetchAudit).toHaveBeenLastCalledWith({ action: 'tenant.suspend', result: 'denied', cursor: 'a-1' });
  });

  it('does not load the audit for staff without access', async () => {
    const { wrapper } = await open('/owner/audit', ['contacts.read']);

    expect(mocked.fetchAudit).not.toHaveBeenCalled();
    expect(wrapper.find('[data-action="export"]').exists()).toBe(false);
  });
});
