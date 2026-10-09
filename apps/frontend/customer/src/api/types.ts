// Mirrors the schemas in contracts/openapi.yaml. Money is always a minor-unit string.

import type { Locale } from '@bw/i18n';

export type { Locale };

export interface User {
  id: string;
  name: string;
  email: string;
  locale: Locale;
  email_verified: boolean;
  mfa_enabled: boolean;
}

export interface Membership {
  tenant_id: string;
  role: 'owner' | 'admin' | 'operator' | 'viewer';
  created_at: string;
}

export interface AuthSession {
  user: User;
  active_tenant_id: string | null;
  memberships: Membership[];
}

export type ConnectorState = 'not_connected' | 'warming_up' | 'fresh' | 'stale' | 'partial';
export type MoneyState = 'provider_not_connected' | 'store_not_connected' | 'store_data_stale' | 'reconciling';
export type PaymentAttemptsState = 'store_not_connected' | 'store_data_stale' | 'no_attempts_seen' | 'no_recent_attempts' | 'observing';
export type BrowserChecksState =
  | 'store_not_verified'
  | 'disabled'
  | 'not_configured'
  | 'scenario_disabled'
  | 'scheduled'
  | 'passing'
  | 'failing'
  | 'not_conclusive';

export interface StoreCoverage {
  connector: { state: ConnectorState; provider: string | null; last_heartbeat_at: string | null; plugin_version: string | null };
  money: { state: MoneyState; providers: { provider: string; status: string }[] };
  payment_attempts: { state: PaymentAttemptsState; last_window_end_at: string | null };
  browser_checks: {
    state: BrowserChecksState;
    last_run_status: string | null;
    last_run_error_code: string | null;
    last_run_finished_at: string | null;
    next_due_at: string | null;
  };
}

export interface Store {
  id: string;
  name: string;
  base_url: string;
  platform: string;
  timezone: string;
  locale: Locale;
  default_currency: string;
  status: 'onboarding' | 'active' | 'paused' | 'degraded' | 'deleted';
  verified_at: string | null;
  browser_enabled: boolean;
  telemetry_enabled: boolean;
  config_version: number;
  coverage: StoreCoverage;
  last_successful_check_at: string | null;
  active_incident_count: number;
}

export interface Page<T> {
  data: T[];
  next_cursor: string | null;
}
