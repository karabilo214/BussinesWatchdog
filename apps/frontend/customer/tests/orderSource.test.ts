import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@bw/api-client';
import * as providers from '@/api/providers';
import * as stores from '@/api/stores';
import type { Integration } from '@/api/types';
import OrderSource from '@/components/OrderSource.vue';
import VerificationPanel from '@/components/VerificationPanel.vue';
import WooCommerceApiPanel from '@/components/WooCommerceApiPanel.vue';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';
import { localization, setLocale } from '@/i18n';
import { makeStore } from './fixtures';

vi.mock('@/api/providers', () => ({ connectWooCommerceApi: vi.fn(), syncProvider: vi.fn() }));
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
    expect((ru.store.verification.method as Record<string, string>).api_key).toBe('Ключом REST API');
  });
});
