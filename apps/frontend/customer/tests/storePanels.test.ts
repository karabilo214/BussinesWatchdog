import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@bw/api-client';
import * as api from '@/api/stores';
import type { StoreVerification } from '@/api/types';
import ConnectorPanel from '@/components/ConnectorPanel.vue';
import StoreSettings from '@/components/StoreSettings.vue';
import VerificationPanel from '@/components/VerificationPanel.vue';
import { errorKey } from '@/composables/errors';
import { localization, setLocale } from '@/i18n';
import { makeStore } from './fixtures';

vi.mock('@/api/stores', () => ({
  getVerification: vi.fn(),
  startVerification: vi.fn(),
  checkVerification: vi.fn(),
  createPairingCode: vi.fn(),
  listStoreIntegrations: vi.fn(),
  revokeIntegration: vi.fn(),
  updateStore: vi.fn(),
}));

const mocked = vi.mocked(api);
const global = { plugins: [localization] };

function verification(overrides: Partial<StoreVerification> = {}): StoreVerification {
  return {
    id: 'v-1',
    store_id: 's-1',
    method: 'dns',
    state: 'pending',
    verified_origin: 'https://kaffee-lindner.example',
    expires_at: '2026-10-10T10:00:00Z',
    verified_at: null,
    reason_code: null,
    attempts: 0,
    last_checked_at: null,
    instructions: { type: 'dns_txt', record_name: '_bw-verify.kaffee-lindner.example', txt_value: 'bw-abc123' },
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
});

describe('VerificationPanel', () => {
  it('keeps showing the DNS record of a pending verification after a reload', async () => {
    mocked.getVerification.mockResolvedValue(verification({ reason_code: 'dns_record_missing' }));
    const wrapper = mount(VerificationPanel, { props: { store: makeStore(), canManage: true }, global });
    await flushPromises();

    expect((wrapper.get('#dns-name').element as HTMLInputElement).value).toBe('_bw-verify.kaffee-lindner.example');
    expect((wrapper.get('#dns-value').element as HTMLInputElement).value).toBe('bw-abc123');
    expect(wrapper.get('[data-reason]').text()).toContain('TXT-запись пока не найдена');
    wrapper.unmount();
  });

  it('offers the plugin method only when a connector exists and translates unknown reasons generically', async () => {
    mocked.getVerification.mockResolvedValue(verification({ state: 'failed', reason_code: 'something_new', instructions: undefined }));
    const wrapper = mount(VerificationPanel, { props: { store: makeStore(), canManage: true }, global });
    await flushPromises();

    expect((wrapper.get('input[value="plugin_challenge"]').element as HTMLInputElement).disabled).toBe(true);
    expect(wrapper.text()).toContain('Сначала подключите плагин.');
    expect(wrapper.get('[data-reason]').text()).toBe('Проверка пока не прошла.');
  });

  it('starts the chosen method and tells the parent once the domain is verified', async () => {
    mocked.getVerification.mockResolvedValue(null);
    mocked.startVerification.mockResolvedValue(verification({ method: 'plugin_challenge', instructions: { type: 'plugin_challenge', url: 'https://x/c/1', body: 'bw-1' } }));
    mocked.checkVerification.mockResolvedValue(verification({ method: 'plugin_challenge', state: 'verified', instructions: undefined }));
    const store = makeStore({ coverage: { ...makeStore().coverage, connector: { state: 'fresh', provider: 'woocommerce', last_heartbeat_at: null, plugin_version: null } } });
    const wrapper = mount(VerificationPanel, { props: { store, canManage: true }, global });
    await flushPromises();

    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocked.startVerification).toHaveBeenCalledWith('s-1', 'plugin_challenge');
    expect(wrapper.text()).toContain('Плагин получит проверочный код');

    await wrapper.findAll('button').find((button) => button.text() === 'Проверить сейчас')!.trigger('click');
    await flushPromises();
    expect(wrapper.emitted('verified')).toHaveLength(1);
    wrapper.unmount();
  });

  it('shows no actions to a read-only member', async () => {
    mocked.getVerification.mockResolvedValue(verification());
    const wrapper = mount(VerificationPanel, { props: { store: makeStore(), canManage: false }, global });
    await flushPromises();

    expect(wrapper.find('form').exists()).toBe(false);
    expect(wrapper.text()).not.toContain('Проверить сейчас');
    wrapper.unmount();
  });
});

describe('ConnectorPanel', () => {
  it('shows the service URL and the one-time code with the plugin steps', async () => {
    mocked.listStoreIntegrations.mockResolvedValue([]);
    mocked.createPairingCode.mockResolvedValue({
      id: 'p-1',
      store_id: 's-1',
      pairing_code: 'bwpc_secret',
      expires_at: '2026-10-09T12:15:00Z',
      saas_endpoint: 'https://watchdog.example/api/v1/pairing/exchange',
      service_url: 'https://watchdog.example',
    });
    const wrapper = mount(ConnectorPanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.text()).toContain('Плагин ещё не подключён.');
    await wrapper.get('button').trigger('click');
    await flushPromises();

    expect((wrapper.get('#pairing-service-url').element as HTMLInputElement).value).toBe('https://watchdog.example');
    expect((wrapper.get('#pairing-code').element as HTMLInputElement).value).toBe('bwpc_secret');
    expect(wrapper.text()).toContain('WooCommerce → Business Watchdog');
    wrapper.unmount();
  });

  it('asks for confirmation before revoking a connector', async () => {
    mocked.listStoreIntegrations.mockResolvedValue([
      { id: 'i-1', store_id: 's-1', provider: 'woocommerce', mode: 'live', source_authority: 'store_reported', status: 'active', connector_version: '0.6.0', last_heartbeat_at: null, created_at: '2026-10-01T00:00:00Z' },
    ]);
    mocked.revokeIntegration.mockResolvedValue({} as never);
    const wrapper = mount(ConnectorPanel, { props: { storeId: 's-1', canManage: true }, global });
    await flushPromises();

    expect(wrapper.get('[data-tone]').attributes('data-tone')).toBe('ok');
    await wrapper.findAll('button').find((button) => button.text() === 'Отключить')!.trigger('click');
    expect(mocked.revokeIntegration).not.toHaveBeenCalled();
    await wrapper.findAll('button').find((button) => button.text() === 'Да, отключить')!.trigger('click');
    await flushPromises();
    expect(mocked.revokeIntegration).toHaveBeenCalledWith('i-1');
    expect(wrapper.emitted('changed')).toHaveLength(1);
  });

  it('hides management buttons from read-only members', async () => {
    mocked.listStoreIntegrations.mockResolvedValue([]);
    const wrapper = mount(ConnectorPanel, { props: { storeId: 's-1', canManage: false }, global });
    await flushPromises();

    expect(wrapper.findAll('button')).toHaveLength(0);
  });
});

describe('StoreSettings', () => {
  it('sends only changed fields with the version and reports a conflict', async () => {
    const store = makeStore({ status: 'active', verified_at: '2026-10-01T00:00:00Z' });
    mocked.updateStore.mockRejectedValue(new ApiError(409, 'version_conflict'));
    const wrapper = mount(StoreSettings, { props: { store, canManage: true }, global });

    await wrapper.get('#settings-name').setValue('Lindner Kaffee');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(mocked.updateStore).toHaveBeenCalledWith(store, { name: 'Lindner Kaffee' });
    expect(wrapper.emitted('stale')).toHaveLength(1);
    expect(wrapper.get('[role="alert"]').text()).toContain('Данные успели измениться');
  });

  it('pauses an active store', async () => {
    const store = makeStore({ status: 'active', verified_at: '2026-10-01T00:00:00Z' });
    mocked.updateStore.mockResolvedValue({ ...store, status: 'paused', config_version: 4 });
    const wrapper = mount(StoreSettings, { props: { store, canManage: true }, global });

    await wrapper.findAll('button').find((button) => button.text() === 'Приостановить мониторинг')!.trigger('click');
    await flushPromises();

    expect(mocked.updateStore).toHaveBeenCalledWith(store, { status: 'paused' });
    expect(wrapper.emitted('updated')![0]![0]).toMatchObject({ status: 'paused' });
  });
});

describe('errorKey', () => {
  it('maps stable codes, never server text', () => {
    expect(errorKey(new ApiError(0, 'network_unavailable'))).toBe('common.error_network');
    expect(errorKey(new ApiError(403, 'tenant_forbidden'))).toBe('common.error_forbidden');
    expect(errorKey(new ApiError(422, 'store_not_verified'))).toBe('store.errors.store_not_verified');
    expect(errorKey(new ApiError(422, 'validation_failed'))).toBe('common.error_validation');
    expect(errorKey(new ApiError(429, 'http_429'))).toBe('common.error_rate_limited');
    expect(errorKey(new Error('boom'))).toBe('common.error_generic');
  });
});
