import { mount, RouterLinkStub } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { Store } from '@/api/types';
import StoreCard from '@/components/StoreCard.vue';
import { localization, setLocale } from '@/i18n';

function store(overrides: Partial<Store> = {}): Store {
  return {
    id: 's-1',
    name: 'Kaffeerösterei Lindner',
    base_url: 'https://kaffee-lindner.example',
    platform: 'woocommerce',
    timezone: 'Europe/Berlin',
    locale: 'de',
    default_currency: 'EUR',
    status: 'active',
    verified_at: '2026-10-01T10:00:00Z',
    capacity_paused_at: null,
    browser_enabled: true,
    telemetry_enabled: false,
    config_version: 3,
    coverage: {
      connector: { state: 'fresh', provider: 'woocommerce', last_heartbeat_at: '2026-10-09T12:00:00Z', plugin_version: '0.6.0' },
      money: { state: 'provider_not_connected', providers: [] },
      payment_attempts: { state: 'observing', last_window_end_at: '2026-10-09T11:55:00Z' },
      browser_checks: { state: 'failing', last_run_status: 'failed', last_run_error_code: 'site_failure', last_run_finished_at: '2026-10-09T11:50:00Z', next_due_at: null },
    },
    last_successful_check_at: null,
    active_incident_count: 2,
    ...overrides,
  };
}

describe('StoreCard', () => {
  it('offers deletion only when the owner flag is set', async () => {
    setLocale('ru');
    const options = { global: { plugins: [localization], stubs: { RouterLink: RouterLinkStub } } };
    const hidden = mount(StoreCard, { props: { store: store() }, ...options });
    expect(hidden.find('[data-action="delete-store"]').exists()).toBe(false);
    const owner = mount(StoreCard, { props: { store: store(), canDelete: true }, ...options });
    await owner.get('[data-action="delete-store"]').trigger('click');
    expect(owner.emitted('delete')?.[0]?.[0]).toMatchObject({ id: 's-1' });
  });
  it('shows each coverage part with its own colour; missing money data is unknown, not fine', () => {
    setLocale('ru');
    const wrapper = mount(StoreCard, { props: { store: store() }, global: { plugins: [localization], stubs: { RouterLink: RouterLinkStub } } });
    const tone = (part: string) => wrapper.get(`[data-coverage="${part}"] [data-tone]`).attributes('data-tone');

    expect(tone('connector')).toBe('ok');
    expect(tone('money')).toBe('unknown');
    expect(tone('payment_attempts')).toBe('ok');
    expect(tone('browser_checks')).toBe('crit');
    expect(wrapper.text()).toContain('Активных инцидентов: 2');
    expect(wrapper.text()).toContain('провайдер не подключён');
    expect(wrapper.text()).toContain('Последняя успешная проверка: ещё не было');
    expect(wrapper.text()).toContain('kaffee-lindner.example');
  });

  it('switches language without missing keys', () => {
    setLocale('de');
    const wrapper = mount(StoreCard, { props: { store: store({ active_incident_count: 0 }) }, global: { plugins: [localization], stubs: { RouterLink: RouterLinkStub } } });

    expect(wrapper.text()).toContain('Keine aktiven Vorfälle');
    expect(wrapper.text()).toContain('kein Zahlungsanbieter verbunden');
    expect(wrapper.text()).not.toMatch(/coverage\.|overview\./);
  });

  it('a store paused by the plan reads as paused and unknown, never as healthy', () => {
    setLocale('ru');
    const paused = store({
      active_incident_count: 0,
      capacity_paused_at: '2026-10-20T00:00:00Z',
      coverage: {
        connector: { state: 'capacity_paused', provider: 'woocommerce', last_heartbeat_at: null, plugin_version: null },
        money: { state: 'capacity_paused', providers: [] },
        payment_attempts: { state: 'capacity_paused', last_window_end_at: null },
        browser_checks: { state: 'capacity_paused', last_run_status: null, last_run_error_code: null, last_run_finished_at: null, next_due_at: null },
      },
    });
    const wrapper = mount(StoreCard, { props: { store: paused }, global: { plugins: [localization], stubs: { RouterLink: RouterLinkStub } } });

    expect(wrapper.findAll('[data-tone="ok"]')).toHaveLength(0);
    expect(wrapper.text()).not.toContain('Нет активных инцидентов');
    expect(wrapper.text()).toContain('неизвестно: магазин на паузе');
    expect(wrapper.get('[data-store-paused="capacity"]').text()).toContain('Данные не принимаются и не сверяются');
  });

  it('after a capacity pause tells that data of the paused period may deviate', () => {
    setLocale('ru');
    const resumed = store({ last_capacity_pause: { from: '2026-10-01T10:00:00Z', until: '2026-10-10T10:00:00Z' } });
    const wrapper = mount(StoreCard, { props: { store: resumed }, global: { plugins: [localization], stubs: { RouterLink: RouterLinkStub } } });

    expect(wrapper.get('[data-pause-deviation]').text()).toContain('возможны отклонения из-за постановки магазина на паузу');
    expect(mount(StoreCard, { props: { store: store() }, global: { plugins: [localization], stubs: { RouterLink: RouterLinkStub } } }).find('[data-pause-deviation]').exists()).toBe(false);
  });
});
