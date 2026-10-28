import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import { ApiError } from '@bw/api-client';
import * as pluginApi from '@/api/plugin';
import * as storesApi from '@/api/stores';
import type { AuthSession, Store } from '@/api/types';
import { localization, setLocale } from '@/i18n';
import ConnectView from '@/views/ConnectView.vue';
import { permissionsFor } from './permissions';

const session = ref<AuthSession | null>(null);

vi.mock('@/api/plugin', () => ({ getPluginConnect: vi.fn(), approvePluginConnect: vi.fn(), cancelPluginConnect: vi.fn(), returnToWordPress: vi.fn() }));
vi.mock('@/api/stores', () => ({ createStore: vi.fn() }));
vi.mock('@/composables/useSession', () => ({ useSession: () => ({ session, load: vi.fn(async () => session.value) }) }));

const plugin = vi.mocked(pluginApi);
const stores = vi.mocked(storesApi);

function sessionFor(role: 'owner' | 'viewer'): AuthSession {
  return {
    user: { id: 'u-me', name: 'Me', salutation: null, first_name: null, last_name: 'Me', company_name: null, email: 'me@example.test', locale: 'ru', email_verified: true, mfa_enabled: false, pending_email_change: null },
    active_tenant_id: 't-1',
    memberships: [{ tenant_id: 't-1', role, permissions: permissionsFor(role), created_at: '2026-10-01T00:00:00Z' }],
  };
}

const request = { site: 'https://shop.example.com', site_host: 'shop.example.com', organization: 'Kaffee GmbH', expires_at: '2026-10-28T10:30:00Z' };

async function mountView() {
  const router = createRouter({ history: createMemoryHistory('/app/'), routes: ['overview', 'plugin-connect'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })) });
  await router.push('/plugin-connect');
  const wrapper = mount(ConnectView, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub, AppLayout: { template: '<div><slot /></div>' } } } });
  await flushPromises();

  return { wrapper, router };
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
  session.value = sessionFor('owner');
});

describe('one-click plugin connection', () => {
  it('creates the store with the site address when there is none and returns to WordPress', async () => {
    plugin.getPluginConnect.mockResolvedValue({ ...request, stores: [] });
    stores.createStore.mockResolvedValue({ id: 's-new' } as Store);
    plugin.approvePluginConnect.mockResolvedValue('https://shop.example.com/wp-admin/admin-post.php?action=business_watchdog_connect_return&state=s&code=c');
    const { wrapper } = await mountView();

    expect(wrapper.text()).toContain('Плагин Business Watchdog на shop.example.com просит подключиться к организации «Kaffee GmbH».');
    expect((wrapper.get('#connect-store-name').element as HTMLInputElement).value).toBe('shop.example.com');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(stores.createStore).toHaveBeenCalledWith(expect.objectContaining({ base_url: 'https://shop.example.com', platform: 'woocommerce', name: 'shop.example.com' }));
    expect(plugin.approvePluginConnect).toHaveBeenCalledWith('s-new');
    expect(plugin.returnToWordPress).toHaveBeenCalledWith('https://shop.example.com/wp-admin/admin-post.php?action=business_watchdog_connect_return&state=s&code=c');
  });

  it('offers only stores the plugin can connect and explains the others', async () => {
    plugin.getPluginConnect.mockResolvedValue({ ...request, stores: [
      { id: 's-api', name: 'API', base_url: request.site, platform: 'woocommerce', order_source: 'woocommerce_api' },
      { id: 's-ok', name: 'Kaffee', base_url: request.site, platform: 'woocommerce', order_source: null },
    ] });
    plugin.approvePluginConnect.mockResolvedValue('https://shop.example.com/back');
    const { wrapper } = await mountView();

    expect(wrapper.get('[data-store="s-api"] input').attributes('disabled')).toBeDefined();
    expect(wrapper.get('[data-store="s-api"]').text()).toContain('сначала отключите API');
    expect((wrapper.get('[data-store="s-ok"] input').element as HTMLInputElement).checked).toBe(true);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(plugin.approvePluginConnect).toHaveBeenCalledWith('s-ok');
    expect(stores.createStore).not.toHaveBeenCalled();
  });

  it('shows the server refusal and stays on the page', async () => {
    plugin.getPluginConnect.mockResolvedValue({ ...request, stores: [{ id: 's-ok', name: 'Kaffee', base_url: request.site, platform: 'woocommerce', order_source: null }] });
    plugin.approvePluginConnect.mockRejectedValue(new ApiError(422, 'plugin_connect_site_mismatch'));
    const { wrapper } = await mountView();
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(wrapper.text()).toContain('Адрес магазина отличается от адреса сайта WordPress.');
    expect(plugin.returnToWordPress).not.toHaveBeenCalled();
    expect(wrapper.get('[data-action="connect"]').attributes('disabled')).toBeUndefined();
  });

  it('explains a missing request and a role without access, and cancels', async () => {
    plugin.getPluginConnect.mockRejectedValue(new ApiError(404, 'plugin_connect_missing'));
    expect((await mountView()).wrapper.find('[data-state="missing"]').exists()).toBe(true);

    session.value = sessionFor('viewer');
    plugin.getPluginConnect.mockRejectedValue(new ApiError(403, 'forbidden'));
    expect((await mountView()).wrapper.find('[data-state="forbidden"]').exists()).toBe(true);

    session.value = sessionFor('owner');
    plugin.getPluginConnect.mockResolvedValue({ ...request, stores: [] });
    plugin.cancelPluginConnect.mockResolvedValue();
    const { wrapper, router } = await mountView();
    await wrapper.get('[data-action="cancel"]').trigger('click');
    await flushPromises();
    expect(plugin.cancelPluginConnect).toHaveBeenCalled();
    expect(router.currentRoute.value.name).toBe('overview');
  });
});
