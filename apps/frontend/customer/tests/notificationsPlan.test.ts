import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import * as billing from '@/api/billing';
import type { Entitlements } from '@/api/billing';
import * as notifications from '@/api/notifications';
import * as stores from '@/api/stores';
import { localization, setLocale } from '@/i18n';
import NotificationsView from '@/views/NotificationsView.vue';
import { permissionsFor } from './permissions';

const session = ref<unknown>({
  user: { id: 'u-1', name: 'Me', email: 'me@example.test', locale: 'ru', email_verified: true, mfa_enabled: false, pending_email_change: null },
  active_tenant_id: 't-1',
  memberships: [{ tenant_id: 't-1', role: 'owner', permissions: permissionsFor('owner'), created_at: '2026-10-01T00:00:00Z' }],
});

vi.mock('@/composables/useSession', () => ({ useSession: () => ({ session, load: vi.fn(async () => session.value), signOut: vi.fn() }) }));
vi.mock('@/api/notifications', () => ({ listChannels: vi.fn(), createEmailChannel: vi.fn(), createTelegramChannel: vi.fn() }));
vi.mock('@/api/stores', () => ({ listStores: vi.fn() }));
vi.mock('@/api/billing', () => ({ fetchEntitlements: vi.fn() }));
vi.mock('@/api/maintenance', () => ({ getMaintenance: vi.fn(async () => null) }));

function entitlements(telegram: boolean, enforced = true): Entitlements {
  return { access_state: 'active', reason_codes: [], enforced, plan_code: 'start', features: [{ code: 'notifications_telegram', included: telegram, reason: telegram ? 'plan' : 'not_in_plan' }], limits: {}, usage: {} as Entitlements['usage'] };
}

async function openForm() {
  const router = createRouter({ history: createMemoryHistory('/app/'), routes: [{ path: '/:p(.*)*', name: 'notifications', component: NotificationsView }, { path: '/billing', name: 'billing', component: { template: '<div />' } }] });
  await router.push('/');
  const wrapper = mount(NotificationsView, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
  await flushPromises();
  await wrapper.findAll('button').find((button) => button.text() === 'Добавить получателя' || button.text().startsWith('Добавить'))!.trigger('click');
  await wrapper.findAll('[data-panel="new-channel"] button').find((button) => button.text().includes('Telegram'))!.trigger('click');
  await wrapper.get('[data-panel="new-channel"] input').setValue('Дежурный');

  return wrapper;
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
  vi.mocked(notifications.listChannels).mockResolvedValue([]);
  vi.mocked(stores.listStores).mockResolvedValue([]);
});

describe('telegram recipients and the plan', () => {
  it('locks Telegram with a note and a link to the plans when the subscription does not include it', async () => {
    vi.mocked(billing.fetchEntitlements).mockResolvedValue(entitlements(false));
    const wrapper = await openForm();

    expect(wrapper.find('[data-telegram-locked]').text()).toBe('по подписке');
    expect(wrapper.get('[data-telegram-plan]').text()).toContain('только с подпиской');
    expect(wrapper.get('[data-panel="new-channel"] button[type="submit"]').attributes('disabled')).toBeDefined();
  });

  it('allows Telegram when the plan includes it or limits are not enforced', async () => {
    for (const state of [entitlements(true), entitlements(false, false)]) {
      vi.mocked(billing.fetchEntitlements).mockResolvedValue(state);
      const wrapper = await openForm();

      expect(wrapper.find('[data-telegram-locked]').exists()).toBe(false);
      expect(wrapper.find('[data-telegram-plan]').exists()).toBe(false);
      expect(wrapper.get('[data-panel="new-channel"] button[type="submit"]').attributes('disabled')).toBeUndefined();
      wrapper.unmount();
    }
  });
});
