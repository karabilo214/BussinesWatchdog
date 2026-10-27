import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@bw/api-client';
import * as providers from '@/api/providers';
import * as plugin from '@/api/plugin';
import * as stores from '@/api/stores';
import type { Integration } from '@/api/types';
import OrderSource from '@/components/OrderSource.vue';
import ConnectorPanel from '@/components/ConnectorPanel.vue';
import ShopwarePanel from '@/components/ShopwarePanel.vue';
import VerificationPanel from '@/components/VerificationPanel.vue';
import WooCommerceApiPanel from '@/components/WooCommerceApiPanel.vue';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';
import { localization, setLocale } from '@/i18n';
import { makeStore } from './fixtures';

vi.mock('@/api/providers', () => ({ connectWooCommerceApi: vi.fn(), connectShopware: vi.fn(), downloadShopwareApp: vi.fn(), downloadShopwareAppUpdate: vi.fn(), setUpWooCommerceWebhook: vi.fn(), checkWooCommerceWebhooks: vi.fn(), syncProvider: vi.fn() }));
vi.mock('@/api/plugin', () => ({ PLUGIN_DOWNLOAD_URL: '/api/v1/downloads/woocommerce-plugin/file', getPluginPackage: vi.fn() }));
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
  vi.mocked(plugin.getPluginPackage).mockResolvedValue({ available: true, file: 'business-watchdog.zip', version: '0.3.2', size_bytes: 1024, sha256: 'a'.repeat(64) });
});

describe('Connector installation', () => {
  it('opens WooCommerce installation instructions and a new pairing code on download', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([]);
    vi.mocked(stores.createPairingCode).mockResolvedValue({ id: 'p-1', store_id: 's-1', pairing_code: 'bwpc_woo', expires_at: '2026-10-21T12:15:00Z', saas_endpoint: '', service_url: 'https://business-watchdog.com' });
    const wrapper = mount(ConnectorPanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.get('[data-plugin-download]').text()).toContain('Контрольная сумма файла SHA-256');
    expect(wrapper.get('[data-plugin-download]').text()).toContain('Это не код подключения');
    await wrapper.get('[data-action="download-plugin"]').trigger('click');
    await flushPromises();
    expect(stores.createPairingCode).toHaveBeenCalledWith('s-1');
    expect(wrapper.get('[data-install-dialog]').text()).toContain('Плагины');
    expect(wrapper.get('[data-install-dialog]').text()).toContain('WooCommerce → Business Watchdog');
    expect((wrapper.get('#pairing-service-url').element as HTMLInputElement).value).toBe('https://business-watchdog.com');
    expect((wrapper.get('#pairing-code').element as HTMLInputElement).value).toBe('bwpc_woo');
    wrapper.unmount();
  });
});

describe('OrderSource', () => {
  it('shows the API path first and keeps plugin setup in a secondary block', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([]);
    const wrapper = mount(OrderSource, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.text()).toContain('Подключить WooCommerce через API');
    expect(wrapper.find('#wc-consumer-key').exists()).toBe(true);
    expect(wrapper.text()).toContain('WooCommerce → Настройки (Settings) → Дополнительно (Advanced)');

    await wrapper.findAll('button').find((button) => button.text() === 'Показать настройку')!.trigger('click');
    expect(wrapper.text()).toContain('Плагин ещё не подключён.');
    expect(wrapper.findAll('button').some((button) => button.text() === 'Получить код подключения')).toBe(true);
  });

  it('shows the paired plugin and prevents a second order source', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([integration('woocommerce')]);
    const wrapper = mount(OrderSource, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.find('[data-connector="plugin"]').exists()).toBe(true);
    expect(wrapper.find('#wc-consumer-key').exists()).toBe(false);
    expect(wrapper.get('[data-other-source]').text()).toContain('сначала отключите плагин');
  });

  it('shows the connected key and hides the pairing code while the API is the source', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([integration('woocommerce_api')]);
    const wrapper = mount(OrderSource, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.text()).toContain('ck_••••1234567');
    expect(wrapper.text()).toContain('только чтение');
    await wrapper.findAll('button').find((button) => button.text() === 'Показать настройку')!.trigger('click');
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
    expect(wrapper.text()).toContain('Доступ по ключу проверен');
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

  it('sets up WooCommerce webhooks without a plugin and shows when the last one arrived', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([integration('woocommerce_api', { health: { key_last7: '1234567' } })]);
    vi.mocked(providers.setUpWooCommerceWebhook).mockResolvedValue({ delivery_url: 'https://business-watchdog.com/api/v1/webhooks/woocommerce/i-1', secret: 'webhook-secret', topics: ['order.created', 'order.updated', 'order.deleted', 'order.restored'] });
    const wrapper = mount(WooCommerceApiPanel, { props: { storeId: 's-1', canManage: true, pluginActive: false }, global });
    await flushPromises();

    expect(wrapper.get('[data-webhook-state]').text()).toContain('каждые 5 минут');
    await wrapper.get('[data-action="set-up-webhook"]').trigger('click');
    await flushPromises();
    expect((wrapper.get('#woo-webhook-url').element as HTMLInputElement).value).toBe('https://business-watchdog.com/api/v1/webhooks/woocommerce/i-1');
    expect((wrapper.get('#woo-webhook-secret').element as HTMLInputElement).value).toBe('webhook-secret');
    expect(wrapper.text()).toContain('order.created, order.updated, order.deleted, order.restored');
    wrapper.unmount();

    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([integration('woocommerce_api', { health: { key_last7: '1234567', webhook_set_up_at: '2026-10-22T09:00:00Z', webhook: { last_received_at: '2026-10-22T09:05:00Z', last_type: 'order.updated' } } })]);
    const live = mount(WooCommerceApiPanel, { props: { storeId: 's-1', canManage: true, pluginActive: false }, global });
    await flushPromises();
    expect(live.get('[data-webhook-state]').text()).toContain('Работают: подписанное событие order.updated');
    expect(live.get('[data-webhook-stage]').text()).toBe('работают');
    expect(live.get('[data-api-limits]').text()).toContain('приходят сразу');
  });

  it('shows the webhook stages and never says working before a signed event', async () => {
    const stageOf = async (health: Record<string, unknown>) => {
      vi.mocked(stores.listStoreIntegrations).mockResolvedValue([integration('woocommerce_api', { health: { key_last7: '1234567', webhook_set_up_at: '2026-10-27T09:00:00Z', ...health } })]);
      const wrapper = mount(WooCommerceApiPanel, { props: { storeId: 's-1', canManage: true, pluginActive: false }, global });
      await flushPromises();

      return wrapper;
    };

    const waiting = await stageOf({});
    expect(waiting.get('[data-webhook-stage]').text()).toBe('ожидаем проверку');
    waiting.unmount();

    const unverified = await stageOf({ webhook_ping: { webhook_id: 7, received_at: '2026-10-27T09:01:00Z', verified: false } });
    expect(unverified.get('[data-webhook-stage]').text()).toBe('ожидаем проверку');
    expect(unverified.get('[data-webhook-state]').text()).toContain('сверяем');
    unverified.unmount();

    const reached = await stageOf({ webhook_ping: { webhook_id: 7, received_at: '2026-10-27T09:01:00Z', verified: true }, webhook_check: { checked_at: '2026-10-27T09:01:10Z', state: 'ok', topics: { 'order.created': 'active', 'order.updated': 'active', 'order.deleted': 'active', 'order.restored': 'active' }, issues: [] } });
    expect(reached.get('[data-webhook-stage]').text()).toBe('подпись не проверена');
    expect(reached.get('[data-webhook-state]').text()).toContain('вебхука №7');
    expect(reached.get('[data-webhook-state]').text()).toContain('нажмите «Обновить»');
    reached.unmount();

    const issues = await stageOf({ webhook_check: { checked_at: '2026-10-27T09:01:10Z', state: 'issues', topics: { 'order.created': 'active', 'order.updated': 'disabled', 'order.deleted': 'missing', 'order.restored': 'active' }, issues: ['secret_newer_than_webhooks'] } });
    expect(issues.get('[data-webhook-stage]').text()).toBe('ожидаем проверку');
    expect(issues.get('[data-topic="order.updated"]').text()).toContain('отключён WooCommerce');
    expect(issues.get('[data-topic="order.deleted"]').text()).toContain('не найден');
    expect(issues.find('[data-issue="secret_newer_than_webhooks"]').exists()).toBe(true);
    issues.unmount();

    const rejected = await stageOf({ webhook: { last_received_at: '2026-10-27T09:02:00Z', last_type: 'order.updated' }, webhook_rejected: { reason: 'signature_invalid', topic: 'order.updated', at: '2026-10-27T09:05:00Z' } });
    expect(rejected.get('[data-webhook-stage]').text()).toBe('проверка не прошла');
    expect(rejected.get('[data-webhook-state]').text()).toContain('подпись не совпала');
    rejected.unmount();

    vi.mocked(providers.checkWooCommerceWebhooks).mockResolvedValue({ checked_at: '2026-10-27T09:06:00Z', state: 'ok', topics: {}, issues: [] });
    const check = await stageOf({});
    await check.get('[data-action="check-webhook"]').trigger('click');
    await flushPromises();
    expect(providers.checkWooCommerceWebhooks).toHaveBeenCalledWith('int-woocommerce_api');
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
    expect(wrapper.find('[data-app-steps]').exists()).toBe(false);
    expect((wrapper.get('[data-method="key"]').element as HTMLInputElement).checked).toBe(true);
    await wrapper.get('[data-method="app"]').setValue(true);
    expect(wrapper.find('[data-app-steps]').exists()).toBe(true);
    expect(wrapper.text()).toContain('Extensions → My extensions → Upload extension');
    expect(wrapper.get('[data-download-app]').attributes('aria-disabled')).toBe('true');
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

  it('offers the one app for every store after the acknowledgement, then a code, and notices the binding', async () => {
    vi.useFakeTimers();
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([]);
    vi.mocked(stores.createPairingCode).mockResolvedValue({ id: 'p-1', store_id: 's-1', pairing_code: 'bwpc_abc', expires_at: '2026-10-21T12:15:00Z', saas_endpoint: '', service_url: 'https://business-watchdog.com' });
    const wrapper = mount(ShopwarePanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();
    await wrapper.get('[data-method="app"]').setValue(true);

    expect(wrapper.get('[data-download-app]').attributes('aria-disabled')).toBe('true');
    expect(wrapper.get('[data-create-app-code]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-acknowledge]').setValue(true);
    expect(wrapper.get('[data-download-app]').attributes('href')).toBe('/api/v1/downloads/shopware-app');
    expect(wrapper.text()).toContain('Open app');

    await wrapper.get('[data-download-app]').trigger('click');
    await flushPromises();
    expect(stores.createPairingCode).toHaveBeenCalledWith('s-1');
    expect(wrapper.get('[data-app-install-dialog]').text()).toContain('Upload extension');
    expect((wrapper.get('#shopware-service-url').element as HTMLInputElement).value).toBe('https://business-watchdog.com');
    expect((wrapper.get('#shopware-app-code').element as HTMLInputElement).value).toBe('bwpc_abc');

    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([integration('shopware', { status: 'active', capabilities: { connection: 'app' }, health: { key_last4: 'ZXCV' } })]);
    await vi.advanceTimersByTimeAsync(5_000);
    await flushPromises();
    expect(wrapper.find('[data-app-code]').exists()).toBe(false);
    expect(wrapper.get('[data-connection]').text()).toContain('ZXCV');
    wrapper.unmount();
    vi.useRealTimers();
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
    expect(current.find('[data-app-test-download]').exists()).toBe(false);
    current.unmount();

    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([{ ...app('1.2.0'), app_update: { installed_version: '1.2.0', latest_version: '1.2.0', available: false, test_download: true } }]);
    const local = mount(ShopwarePanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();
    expect(local.get('[data-app-test-download]').text()).toContain('Скачать App 1.2.0');
    await local.get('[data-app-test-download] button').trigger('click');
    await flushPromises();
    expect(providers.downloadShopwareAppUpdate).toHaveBeenCalledTimes(2);
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
