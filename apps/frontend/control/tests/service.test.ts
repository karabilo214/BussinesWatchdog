import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api';
import type { ServiceHealth, StaffMe } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';

vi.mock('@/api', () => ({ fetchMe: vi.fn(), login: vi.fn(), submitMfa: vi.fn(), logout: vi.fn(), fetchServiceHealth: vi.fn(), fetchDeadOutbox: vi.fn(), retryDeadOutbox: vi.fn(), fetchOwnerAttention: vi.fn() }));
vi.mock('@/composables/useOwnerBadges', async () => {
  const { ref } = await import('vue');
  return { useOwnerBadges: () => ({ badges: ref({}) }) };
});

const mocked = vi.mocked(api);
const QUEUE = { waiting: 0, oldest_age_seconds: null, problems: 0, state: 'ok' as const };
const HEALTH: ServiceHealth = {
  as_of: '2026-10-20T10:00:00Z',
  state: 'crit',
  components: { database: 'ok', redis: 'crit', cache: 'ok', keyring: 'ok' },
  queues: { event_inbox: QUEUE, domain_outbox: { waiting: 1, oldest_age_seconds: 1260, problems: 1, state: 'warn' }, billing_events: { ...QUEUE, last_received_at: null }, reconciliation: QUEUE, notifications: QUEUE, jobs: { ...QUEUE, waiting: 3, connection: 'redis', queues: ['sync', 'default'], delayed: 1, reserved: 2, workers_seen: 0, last_processed_at: null } },
  browser_checks: { waiting: 1, oldest_age_seconds: 300, problems: 0, state: 'crit', running: 0, workers_seen: 0 },
  connector_sync: { active: 2, stale: 1, state: 'warn' },
  thresholds: { warn_seconds: 900, crit_seconds: 3600, sync_stale_hours: 24 },
  version: { app: '2026.10.20-1', environment: 'production', php: '8.4.1', laravel: '12.0.0' },
};

function staff(capabilities: string[]): StaffMe {
  return { staff_id: 's-1', name: 'Ops', first_name: null, last_name: 'Ops', photo_url: null, email: 'ops@example.test', locale: 'ru', role: 'platform_member', mfa_enabled: true, capabilities, billing_mode: 'live', session: { idle_minutes: 30, absolute_hours: 8 } };
}

async function open(capabilities: string[]) {
  mocked.fetchMe.mockResolvedValue(staff(capabilities));
  await useStaff().load(true);
  const router = buildRouter();
  await router.push('/owner/service');
  await router.isReady();
  const wrapper = mount(App, { global: { plugins: [localization, router] } });
  await flushPromises();

  return wrapper;
}

beforeEach(() => {
  vi.clearAllMocks();
  mocked.fetchOwnerAttention.mockResolvedValue({});
  useStaff().forget();
  localization.setLocale('ru');
});

describe('service', () => {
  it('shows components, queue ages and problems, browser workers, connector sync and the release', async () => {
    mocked.fetchServiceHealth.mockResolvedValue(HEALTH);
    const wrapper = await open(['service.read']);

    expect(wrapper.get('[data-state]').text()).toContain('Проблема');
    expect(wrapper.get('[data-component="redis"]').text()).toContain('Проблема');
    expect(wrapper.get('[data-queue="domain_outbox"]').text()).toContain('21');
    expect(wrapper.get('[data-queue="domain_outbox"]').text()).toContain('Внимание');
    expect(wrapper.get('[data-jobs-source]').text()).toBe('очередь: redis (sync, default)');
    expect(wrapper.get('[data-jobs-detail]').text()).toBe('отложены: 1 · выполняются: 2');
    expect(wrapper.get('[data-jobs-workers]').text()).toContain('обработчиков на связи: 0');
    expect(wrapper.get('[data-jobs-workers]').classes()).toContain('text-crit');
    expect(wrapper.get('[data-section="browser"]').text()).toContain('воркеров на связи за 5 минут: 0');
    expect(wrapper.get('[data-section="sync"]').text()).toContain('больше 24 ч: 1');
    expect(wrapper.get('[data-section="version"]').text()).toContain('2026.10.20-1');
    expect(wrapper.find('[data-section="dead-outbox"]').exists()).toBe(false);
    expect(mocked.fetchDeadOutbox).not.toHaveBeenCalled();

    await wrapper.get('[data-action="refresh"]').trigger('click');
    await flushPromises();
    expect(mocked.fetchServiceHealth).toHaveBeenCalledTimes(2);
  });

  it('lists dead outbox entries and confirms an audited retry with reason and code', async () => {
    mocked.fetchServiceHealth.mockResolvedValue(HEALTH);
    mocked.fetchDeadOutbox.mockResolvedValue([{ id: 'dead-1', topic: 'event_inbox.received', error_code: 'worker_failed', attempts: 5, created_at: '2026-10-19T10:00:00Z' }]);
    mocked.retryDeadOutbox.mockResolvedValue();
    const wrapper = await open(['service.read', 'service.manage']);

    expect(wrapper.get('[data-dead-outbox="dead-1"]').text()).toContain('worker_failed');
    await wrapper.get('[data-retry="dead-1"]').trigger('click');
    await wrapper.get('[data-field="retry-reason"]').setValue('Worker fixed');
    await wrapper.get('[data-field="retry-code"]').setValue('123456');
    await wrapper.get('[data-retry-form]').trigger('submit');
    await flushPromises();

    expect(mocked.retryDeadOutbox).toHaveBeenCalledWith('dead-1', 'Worker fixed', '123456');
    expect(mocked.fetchDeadOutbox).toHaveBeenCalledTimes(2);
    expect(wrapper.find('[data-retry-form]').exists()).toBe(false);
  });

  it('does not load the summary for staff without access', async () => {
    const wrapper = await open(['contacts.read']);

    expect(mocked.fetchServiceHealth).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('Раздел доступен владельцу и администратору сервиса.');
  });
});
