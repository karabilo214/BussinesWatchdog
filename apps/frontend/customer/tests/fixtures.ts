import type { Store } from '@/api/types';

export function makeStore(overrides: Partial<Store> = {}): Store {
  return {
    id: 's-1',
    name: 'Kaffeerösterei Lindner',
    base_url: 'https://kaffee-lindner.example',
    platform: 'woocommerce',
    timezone: 'Europe/Berlin',
    locale: 'de',
    default_currency: 'EUR',
    status: 'onboarding',
    verified_at: null,
    browser_enabled: false,
    telemetry_enabled: false,
    config_version: 3,
    coverage: {
      connector: { state: 'not_connected', provider: null, last_heartbeat_at: null, plugin_version: null },
      money: { state: 'store_not_connected', providers: [] },
      payment_attempts: { state: 'store_not_connected', last_window_end_at: null },
      browser_checks: { state: 'store_not_verified', last_run_status: null, last_run_error_code: null, last_run_finished_at: null, next_due_at: null },
    },
    last_successful_check_at: null,
    active_incident_count: 0,
    ...overrides,
  };
}
