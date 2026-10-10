import { readdirSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as providers from '@/api/providers';
import * as stores from '@/api/stores';
import type { Integration } from '@/api/types';
import PaymentProviders from '@/components/PaymentProviders.vue';
import PayPalPanel from '@/components/PayPalPanel.vue';
import ProviderPanel from '@/components/ProviderPanel.vue';
import en from '@/i18n/locales/en.json';
import { localization, setLocale } from '@/i18n';

vi.mock('@/api/providers', () => ({ connectStripe: vi.fn(), connectPayPal: vi.fn(), setPayPalWebhookId: vi.fn(), setWebhookSecret: vi.fn(), syncProvider: vi.fn(), webhookUrl: (id: string) => `https://app.example/api/v1/webhooks/stripe/${id}` }));
vi.mock('@/api/stores', () => ({ listStoreIntegrations: vi.fn(), revokeIntegration: vi.fn() }));

const global = { plugins: [localization] };

function stripe(overrides: Partial<Integration> = {}): Integration {
  return {
    id: 'int-1', store_id: 's-1', provider: 'stripe', mode: 'test', source_authority: 'independent_provider', status: 'active',
    external_account_id: 'acct_1Smoke', connector_version: 'stripe-adapter-1', last_heartbeat_at: null, last_successful_sync_at: '2026-10-10T08:00:00Z',
    health: { key_last4: 'cdef', account_verified: true, sync: { emitted: 3, unchanged: 1, complete: true } },
    credentials: [{ id: 'c-1', kind: 'stripe_api', status: 'active', created_at: null }], created_at: '2026-10-10T07:00:00Z',
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
});

describe('ProviderPanel', () => {
  it('accepts only a restricted key and connects with it', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([]);
    vi.mocked(providers.connectStripe).mockResolvedValue(stripe());
    const wrapper = mount(ProviderPanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();
    const button = () => wrapper.findAll('button').find((item) => item.text() === 'Подключить Stripe')!;

    await wrapper.get('#stripe-key').setValue('sk_live_51Full0123456789abc');
    expect(wrapper.text()).toContain('Это полный секретный ключ');
    expect(button().attributes('disabled')).toBeDefined();

    await wrapper.get('#stripe-key').setValue('rk_test_51Smoke0123456789abcdef');
    expect(button().attributes('disabled')).toBeUndefined();
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(providers.connectStripe).toHaveBeenCalledWith('s-1', { restricted_api_key: 'rk_test_51Smoke0123456789abcdef' });
    expect(wrapper.emitted('changed')).toHaveLength(1);
    expect(wrapper.text()).toContain('rk_••••cdef');
    expect((wrapper.find('#stripe-key').exists())).toBe(false);
  });

  it('shows sync state, syncs on demand and disconnects only after confirmation', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([stripe()]);
    vi.mocked(providers.syncProvider).mockResolvedValue({ status: 'ok', emitted: 5, unchanged: 0, complete: true, integration: stripe({ health: { key_last4: 'cdef', sync: { emitted: 5, unchanged: 0, complete: true } } }) });
    vi.mocked(stores.revokeIntegration).mockResolvedValue(stripe({ status: 'revoked' }));
    const wrapper = mount(ProviderPanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.text()).toContain('тестовый');
    expect(wrapper.text()).toContain('не настроен — данные приходят раз в 15 минут');
    await wrapper.findAll('button').find((item) => item.text() === 'Синхронизировать сейчас')!.trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-last-sync]').text()).toContain('новых событий: 5');

    await wrapper.findAll('button').find((item) => item.text() === 'Отключить')!.trigger('click');
    expect(stores.revokeIntegration).not.toHaveBeenCalled();
    await wrapper.findAll('button').find((item) => item.text() === 'Отключить Stripe')!.trigger('click');
    await flushPromises();
    expect(stores.revokeIntegration).toHaveBeenCalledWith('int-1');
    expect(wrapper.emitted('changed')).toHaveLength(1);
  });

  it('explains a rejected key and offers no sync', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([stripe({ status: 'degraded', health: { key_last4: 'cdef', last_error: { code: 'stripe_key_rejected', at: '2026-10-10T08:00:00Z' } } })]);
    const wrapper = mount(ProviderPanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.find('[data-key-rejected]').exists()).toBe(true);
    expect(wrapper.text()).not.toContain('Синхронизировать сейчас');
  });

  it('every Stripe rejection and sync error code is explained', () => {
    const dir = resolve(__dirname, '../../../backend/app/Support/Providers/Stripe');
    const files = [...readdirSync(dir).map((name) => resolve(dir, name)), resolve(__dirname, '../../../backend/app/Http/Controllers/Api/V1/Integrations/StripeIntegrationController.php')];
    const source = files.map((file) => readFileSync(file, 'utf8')).join('\n');
    const codes = new Set([...source.matchAll(/'(stripe_[a-z_]+|provider_already_connected|integration_not_active)'/g)].map((match) => match[1]!));

    expect(codes.size).toBeGreaterThan(8);

    for (const code of codes) {
      expect((en.errors as Record<string, string>)[code], code).toBeTruthy();
    }
  });
});

function paypal(overrides: Partial<Integration> = {}): Integration {
  return {
    id: 'int-pp', store_id: 's-1', provider: 'paypal', mode: 'test', source_authority: 'independent_provider', status: 'active',
    external_account_id: 'APP-6TS77435KN2790124', connector_version: 'paypal-adapter-1', last_heartbeat_at: null, last_successful_sync_at: '2026-10-10T15:00:00Z',
    health: { client_id_last4: 'AbCd', write_scopes: ['capture', 'payments_v1', 'refund'], sync: { emitted: 4, unchanged: 0, complete: true, search_refreshed_at: '2026-10-10T13:29:59Z' } },
    credentials: [{ id: 'c-2', kind: 'paypal_client', status: 'active', created_at: null }], created_at: '2026-10-10T14:00:00Z', webhook_url: 'https://app.example/api/v1/webhooks/paypal/int-pp',
    ...overrides,
  };
}

describe('PaymentProviders', () => {
  it('shows one block with a tab per provider and opens the connected one', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([paypal()]);
    const wrapper = mount(PaymentProviders, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    const tabs = wrapper.findAll('[role="tab"]');
    expect(tabs.map((tab) => tab.attributes('data-provider-tab'))).toEqual(['stripe', 'paypal']);
    expect(wrapper.get('[data-provider-tab="paypal"]').attributes('aria-selected')).toBe('true');
    expect(wrapper.get('[data-provider-tab="stripe"]').text()).toContain('не подключён');
    expect(wrapper.find('[data-provider="paypal"]').exists()).toBe(true);
    expect(wrapper.find('[data-provider="stripe"]').exists()).toBe(false);

    await wrapper.get('[data-provider-tab="stripe"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-provider="stripe"]').exists()).toBe(true);
    expect(wrapper.find('[data-provider="paypal"]').exists()).toBe(false);
  });

  it('starts on Stripe when nothing is connected', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([]);
    const wrapper = mount(PaymentProviders, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.get('[data-provider-tab="stripe"]').attributes('aria-selected')).toBe('true');
  });
});

describe('PayPalPanel', () => {
  it('connects only after the write-access warning is acknowledged', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([]);
    vi.mocked(providers.connectPayPal).mockResolvedValue(paypal());
    const wrapper = mount(PayPalPanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();
    const button = () => wrapper.findAll('button').find((item) => item.text() === 'Подключить PayPal')!;

    expect(wrapper.get('[data-write-warning]').text()).toContain('PayPal не умеет выдавать доступ только на чтение');
    await wrapper.get('select').setValue('sandbox');
    await wrapper.get('#paypal-client-id').setValue('AaBbCcDdEeFfGgHhIiJjKkLlMm0123456789');
    await wrapper.get('#paypal-client-secret').setValue('EeFfGgHhIiJjKkLlMmNnOoPp0123456789');
    expect(button().attributes('disabled')).toBeDefined();
    await wrapper.get('[data-acknowledge]').setValue(true);
    expect(button().attributes('disabled')).toBeUndefined();
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(providers.connectPayPal).toHaveBeenCalledWith('s-1', { environment: 'sandbox', client_id: 'AaBbCcDdEeFfGgHhIiJjKkLlMm0123456789', client_secret: 'EeFfGgHhIiJjKkLlMmNnOoPp0123456789', write_access_acknowledged: true });
    expect(wrapper.get('[data-write-scopes]').text()).toContain('возвраты');
    expect(wrapper.text()).toContain('тестовая (Sandbox)');
    expect(wrapper.text()).toContain('Список платежей PayPal обновлён до');
    expect(wrapper.get('[data-matching-note]').text()).toContain('Сопоставление по metadata для PayPal не поддерживается');
    expect(wrapper.find('#paypal-client-secret').exists()).toBe(false);
  });

  it('saves a webhook id and explains rejected credentials', async () => {
    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([paypal()]);
    vi.mocked(providers.setPayPalWebhookId).mockResolvedValue(paypal({ credentials: [{ id: 'c-3', kind: 'paypal_webhook', status: 'active', created_at: null }] }));
    const wrapper = mount(PayPalPanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    await wrapper.findAll('button').find((item) => item.text() === 'Webhook')!.trigger('click');
    expect((wrapper.get('#paypal-webhook-url').element as HTMLInputElement).value).toBe('https://app.example/api/v1/webhooks/paypal/int-pp');
    await wrapper.get('#paypal-webhook-id').setValue('8PT597110X687430LKGECATA');
    await wrapper.get('[data-webhook-form]').trigger('submit');
    await flushPromises();
    expect(providers.setPayPalWebhookId).toHaveBeenCalledWith('int-pp', '8PT597110X687430LKGECATA');
    expect(wrapper.text()).toContain('Webhook ID сохранён.');

    vi.mocked(stores.listStoreIntegrations).mockResolvedValue([paypal({ status: 'degraded', health: { last_error: { code: 'paypal_credentials_rejected', at: '2026-10-10T15:00:00Z' } } })]);
    const degraded = mount(PayPalPanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();
    expect(degraded.find('[data-key-rejected]').exists()).toBe(true);
    expect(degraded.text()).not.toContain('Синхронизировать сейчас');
  });

  it('every PayPal rejection and sync error code is explained', () => {
    const dir = resolve(__dirname, '../../../backend/app/Support/Providers/PayPal');
    const files = [...readdirSync(dir).map((name) => resolve(dir, name)), resolve(__dirname, '../../../backend/app/Http/Controllers/Api/V1/Integrations/PayPalIntegrationController.php')];
    const source = files.map((file) => readFileSync(file, 'utf8')).join('\n');
    const codes = new Set([...source.matchAll(/'(paypal_[a-z_]+)'/g)].map((match) => match[1]!).filter((code) => !['paypal_client', 'paypal_webhook', 'paypal_reference_id', 'paypal_reference_id_type'].includes(code)));

    expect(codes.size).toBeGreaterThan(8);

    for (const code of codes) {
      expect((en.errors as Record<string, string>)[code], code).toBeTruthy();
    }
  });
});

