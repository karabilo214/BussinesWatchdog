import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { Store } from '@/api/types';
import StoreCard from '@/components/StoreCard.vue';
import { i18n, setLocale } from '@/i18n';

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
  it('shows each coverage part with its own colour; missing money data is unknown, not fine', () => {
    setLocale('ru');
    const wrapper = mount(StoreCard, { props: { store: store() }, global: { plugins: [i18n] } });
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
    const wrapper = mount(StoreCard, { props: { store: store({ active_incident_count: 0 }) }, global: { plugins: [i18n] } });

    expect(wrapper.text()).toContain('Keine aktiven Vorfälle');
    expect(wrapper.text()).toContain('kein Zahlungsanbieter verbunden');
    expect(wrapper.text()).not.toMatch(/coverage\.|overview\./);
  });
});
