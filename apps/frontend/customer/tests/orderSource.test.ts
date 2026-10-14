import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@bw/api-client';
import * as providers from '@/api/providers';
import * as stores from '@/api/stores';
import type { Integration } from '@/api/types';
import OrderSource from '@/components/OrderSource.vue';
import ShopwarePanel from '@/components/ShopwarePanel.vue';
import VerificationPanel from '@/components/VerificationPanel.vue';
import WooCommerceApiPanel from '@/components/WooCommerceApiPanel.vue';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';
import { localization, setLocale } from '@/i18n';
import { makeStore } from './fixtures';

vi.mock('@/api/providers', () => ({ connectWooCommerceApi: vi.fn(), connectShopware: vi.fn(), downloadShopwareApp: vi.fn(), downloadShopwareAppUpdate: vi.fn(), syncProvider: vi.fn() }));
vi.mock('@/api/stores', () => ({
  listStoreIntegrations: vi.fn(),
  revokeIntegration: vi.fn(),
  createPairingCode: vi.fn(),
  getVerification: vi.fn(),
  startVerification: vi.fn(),
  checkVerification: vi.fn(),
}));

const global = { plugins: [localization] };
const KEY = 'ck_0123456789abcdef0123456789abcdef01234567';
const SECRET = 'cs_89abcdef0123456789abcdef0123456789abcdef';

function integration(provider: string, overrides: Partial<Integration> = {}): Integration {
  return {
    id: `int-${provider}`, store_id: 's-1', provider, mode: 'live', source_authority: 'store_reported', status: 'active',
    connector_version: provider === 'woocommerce' ? '0.2.0' : 'woocommerce-api-1', last_heartbeat_at: null, last_successful_sync_at: '2026-10-11T08:00:00Z',
    health: { key_last7: '1234567', sync: { emitted: 6, unchanged: 0, complete: true } }, created_at: '2026-10-11T07:00:00Z',
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
});

describe('OrderSource', () => {
  it('recommends the REST API key when nothing is connected and keeps the plugin one tab away', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([]);
    const wrapper = mount(OrderSource, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.get('[data-source-tab="api"]').attributes('aria-selected')).toBe('true');
    expect(wrapper.get('[data-source-tab="api"]').text()).toContain('рекомендуется');
    expect(wrapper.find('#wc-consumer-key').exists()).toBe(true);
    expect(wrapper.text()).toContain('WooCommerce → Настройки → Дополнительно → REST API');

    await wrapper.get('[data-source-tab="plugin"]').trigger('click');
    expect(wrapper.text()).toContain('Плагин ещё не подключён.');
    expect(wrapper.findAll('button').some((button) => button.text() === 'Получить код подключения')).toBe(true);
  });

  it('opens the plugin tab for a paired store and does not offer a second source', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([integration('woocommerce')]);
    const wrapper = mount(OrderSource, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.get('[data-source-tab="plugin"]').attributes('aria-selected')).toBe('true');
    await wrapper.get('[data-source-tab="api"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('#wc-consumer-key').exists()).toBe(false);
    expect(wrapper.get('[data-other-source]').text()).toContain('сначала отключите плагин');
  });

  it('shows the connected key and hides the pairing code while the API is the source', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([integration('woocommerce_api')]);
    const wrapper = mount(OrderSource, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.text()).toContain('ck_••••1234567');
    expect(wrapper.text()).toContain('только чтение');
    await wrapper.get('[data-source-tab="plugin"]').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-other-source]').text()).toContain('сначала отключите ключ');
    expect(wrapper.findAll('button').some((button) => /код/i.test(button.text()))).toBe(false);
  });
});

describe('WooCommerceApiPanel', () => {
  it('connects only a well-formed key and secret and explains a key with write permission', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([]);
    vi.mocked(providers.connectWooCommerceApi).mockRejectedValueOnce(new ApiError(422, 'woocommerce_key_not_read_only')).mockResolvedValueOnce(integration('woocommerce_api'));
    const wrapper = mount(WooCommerceApiPanel, { props: { storeId: 's-1', canManage: true, pluginActive: false }, global });
    await flushPromises();
    const submit = () => wrapper.get('button[type="submit"]');

    await wrapper.get('#wc-consumer-key').setValue('ck_short');
    expect(wrapper.text()).toContain('40 символов');
    expect(submit().attributes('disabled')).toBeDefined();

    await wrapper.get('#wc-consumer-key').setValue(KEY);
    await wrapper.get('#wc-consumer-secret').setValue(SECRET);
    expect(submit().attributes('disabled')).toBeUndefined();
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.get('[role="alert"]').text()).toContain('право записи');

    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(providers.connectWooCommerceApi).toHaveBeenLastCalledWith('s-1', { consumer_key: KEY, consumer_secret: SECRET });
    expect(wrapper.emitted('changed')).toHaveLength(1);
    expect(wrapper.text()).toContain('магазин подтверждён');
    expect(wrapper.find('#wc-consumer-secret').exists()).toBe(false);
  });

  it('warns about late refunds and the payment mode, and says when the key was rejected', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([integration('woocommerce_api', { status: 'degraded', health: { key_last7: '1234567', key_rejected_at: '2026-10-11T09:00:00Z' } })]);
    const wrapper = mount(WooCommerceApiPanel, { props: { storeId: 's-1', canManage: true, pluginActive: false }, global });
    await flushPromises();

    expect(wrapper.find('[data-key-rejected]').exists()).toBe(true);
    expect(wrapper.text()).toContain('старше 7.9');
    expect(wrapper.text()).toContain('нужен плагин');
    expect(wrapper.findAll('button').some((button) => button.text() === 'Синхронизировать сейчас')).toBe(false);
  });

  it('every REST API connector error code is explained in every language', () => {
    const dir = resolve(__dirname, '../../../backend/app/Support/Providers/WooCommerce');
    const source = readdirSync(dir).map((name) => readFileSync(resolve(dir, name), 'utf8')).join('\n');
    const codes = new Set([...source.matchAll(/'(woocommerce_[a-z_]+|order_source_already_connected)'/g)].map((match) => match[1]!).filter((code) => code !== 'woocommerce_api' && !code.startsWith('woocommerce_rest_')));

    expect(codes.size).toBeGreaterThan(12);

    for (const locale of [ru, en, de]) {
      for (const code of codes) {
        expect((locale.errors as Record<string, string>)[code], code).toBeTruthy();
      }
    }
  });
});

describe('VerificationPanel with a REST API connection', () => {
  it('suggests the key, keeps the plugin method for paired stores only and names the api_key method', async () => {
    vi.mocked(stores.getVerification).mockResolvedValue(null);
    const store = makeStore({ verified_at: null, coverage: { ...makeStore().coverage, connector: { state: 'fresh', provider: 'woocommerce_api', last_heartbeat_at: null, last_sync_at: '2026-10-11T08:00:00Z', plugin_version: null } } });
    const wrapper = mount(VerificationPanel, { props: { store, canManage: true }, global });
    await flushPromises();

    expect(wrapper.find('[data-api-key-hint]').exists()).toBe(true);
    expect((wrapper.get('input[value="plugin_challenge"]').element as HTMLInputElement).disabled).toBe(true);
    expect((ru.store.verification.method as Record<string, string>).api_key).toBe('Ключом API');
  });
});

describe('Shopware order source', () => {
  it('shows only the Shopware integration panel for a Shopware store', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([]);
    const wrapper = mount(OrderSource, { props: { storeId: 's-1', canManage: true, platform: 'shopware' }, global });
    await flushPromises();

    expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
    expect(wrapper.find('[data-connector="shopware"]').exists()).toBe(true);
    expect(wrapper.find('[data-app-steps]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Extensions → My extensions → Upload extension');
    expect(wrapper.get('[data-download-app]').attributes('disabled')).toBeDefined();
  });

  it('connects only after the state-change risk is acknowledged and never with a malformed key', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([]);
    vi.mocked(providers.connectShopware).mockResolvedValue(integration('shopware', { health: { key_last4: 'ZXCV' } }));
    const wrapper = mount(ShopwarePanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();
    await wrapper.get('[data-method="key"]').setValue(true);
    const submit = () => wrapper.get('button[type="submit"]');

    await wrapper.get('#sw-access-key').setValue('nope');
    expect(wrapper.text()).toContain('начинается с SWIA');
    await wrapper.get('#sw-access-key').setValue('SWIAQWERTYUIOPASDFGHJKLZXCV');
    await wrapper.get('#sw-secret').setValue('S3cretAccessKeyForShopwareIntegration');
    expect(submit().attributes('disabled')).toBeDefined();
    expect(wrapper.get('[data-state-risk]').text()).toContain('менять статусы заказов, оплат и доставки');

    await wrapper.get('[data-acknowledge]').setValue(true);
    expect(submit().attributes('disabled')).toBeUndefined();
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(providers.connectShopware).toHaveBeenCalledWith('s-1', { access_key: 'SWIAQWERTYUIOPASDFGHJKLZXCV', secret_access_key: 'S3cretAccessKeyForShopwareIntegration', state_change_risk_acknowledged: true });
    expect(wrapper.text()).toContain('SWIA••••ZXCV');
    expect(wrapper.get('[data-connection]').text()).toContain('ключ интеграции');
    expect(wrapper.text()).toContain('частичном возврате');
  });

  it('downloads the app only after the acknowledgement and then waits for Shopware', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValueOnce([]).mockResolvedValue([integration('shopware', { status: 'pending', capabilities: { connection: 'app' } })]);
    vi.mocked(providers.downloadShopwareApp).mockResolvedValue({ blob: new Blob(['zip']), filename: 'BusinessWatchdog.zip' });
    URL.createObjectURL = vi.fn(() => 'blob:app');
    URL.revokeObjectURL = vi.fn();
    const wrapper = mount(ShopwarePanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.get('[data-download-app]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-acknowledge]').setValue(true);
    await wrapper.get('[data-download-app]').trigger('click');
    await flushPromises();

    expect(providers.downloadShopwareApp).toHaveBeenCalledWith('s-1');
    expect(wrapper.find('[data-app-waiting]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Ждём установки приложения');
    wrapper.unmount();
  });

  it('offers an app update for the same connection when the shop runs an older version', async () => {
    const app = (installed: string | null) => integration('shopware', { capabilities: { connection: 'app' }, health: { key_last4: 'ZXCV' }, app_update: { installed_version: installed, latest_version: '1.2.0', available: installed !== '1.2.0' } });
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([app('1.0.0')]);
    vi.mocked(providers.downloadShopwareAppUpdate).mockResolvedValue({ blob: new Blob(['zip']), filename: 'BusinessWatchdog.zip' });
    URL.createObjectURL = vi.fn(() => 'blob:app');
    URL.revokeObjectURL = vi.fn();
    const wrapper = mount(ShopwarePanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.get('[data-app-version]').text()).toContain('1.0.0');
    expect(wrapper.get('[data-app-version]').text()).toContain('доступна версия 1.2.0');
    expect(wrapper.get('[data-app-update]').text()).toContain('нажмите «Update»');
    await wrapper.get('[data-download-update]').trigger('click');
    await flushPromises();
    expect(providers.downloadShopwareAppUpdate).toHaveBeenCalledWith('s-1');
    expect(wrapper.text()).toContain('Файл обновления скачан');
    wrapper.unmount();

    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([app('1.2.0')]);
    const current = mount(ShopwarePanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();
    expect(current.get('[data-app-version]').text()).toBe('1.2.0');
    expect(current.find('[data-app-update]').exists()).toBe(false);
  });

  it('every Shopware connector error code is explained in every language', () => {
    const dir = resolve(__dirname, '../../../backend/app/Support/Providers/Shopware');
    const source = readdirSync(dir).map((name) => readFileSync(resolve(dir, name), 'utf8')).join('\n');
    const codes = new Set([...source.matchAll(/'(shopware_[a-z_]+)'/g)].map((match) => match[1]!).filter((code) => !['shopware_integration', 'shopware_sync', 'shopware_app'].includes(code)));

    expect(codes.size).toBeGreaterThan(8);

    for (const locale of [ru, en, de]) {
      for (const code of codes) {
        expect((locale.errors as Record<string, string>)[code], code).toBeTruthy();
      }
    }
  });
});
