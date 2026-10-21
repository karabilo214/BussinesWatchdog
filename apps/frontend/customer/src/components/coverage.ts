import type { Tone } from '@bw/ui';
import type { BrowserChecksState, CheckoutScriptState, ConnectorState, MoneyState, PaymentAttemptsState } from '@/api/types';

/** Coverage state → status colour (docs/design/palette.md). Only the healthy state of each part is green. */
export const CONNECTOR_TONES: Record<ConnectorState, Tone> = {
  fresh: 'ok',
  partial: 'warn',
  stale: 'crit',
  warming_up: 'unknown',
  not_connected: 'unknown',
  capacity_paused: 'unknown',
};

export const MONEY_TONES: Record<MoneyState, Tone> = {
  reconciling: 'ok',
  provider_not_connected: 'unknown',
  store_not_connected: 'unknown',
  store_data_stale: 'unknown',
  capacity_paused: 'unknown',
};

export const PAYMENT_ATTEMPTS_TONES: Record<PaymentAttemptsState, Tone> = {
  observing: 'ok',
  no_recent_attempts: 'unknown',
  no_attempts_seen: 'unknown',
  store_data_stale: 'unknown',
  store_not_connected: 'unknown',
  capacity_paused: 'unknown',
};

export const BROWSER_CHECK_TONES: Record<BrowserChecksState, Tone> = {
  passing: 'ok',
  failing: 'crit',
  not_conclusive: 'unknown',
  scheduled: 'unknown',
  scenario_disabled: 'unknown',
  not_configured: 'unknown',
  disabled: 'unknown',
  store_not_verified: 'unknown',
  capacity_paused: 'unknown',
};

export const CHECKOUT_SCRIPT_TONES: Record<CheckoutScriptState, Tone> = {
  observing: 'ok',
  not_seen_by_check: 'warn',
  elements_not_found: 'warn',
  no_recent_reports: 'unknown',
  waiting: 'unknown',
  not_in_plan: 'unknown',
  check_does_not_reach_payment: 'unknown',
  store_not_active: 'unknown',
  app_not_active: 'unknown',
  connection_without_script: 'unknown',
  needs_plugin: 'unknown',
  needs_app: 'unknown',
  not_available: 'unknown',
  capacity_paused: 'unknown',
};
