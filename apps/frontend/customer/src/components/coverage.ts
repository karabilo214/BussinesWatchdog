import type { Tone } from '@bw/ui';
import type { BrowserChecksState, ConnectorState, MoneyState, PaymentAttemptsState } from '@/api/types';

/** Coverage state → status colour (docs/design/palette.md). Only the healthy state of each part is green. */
export const CONNECTOR_TONES: Record<ConnectorState, Tone> = {
  fresh: 'ok',
  partial: 'warn',
  stale: 'crit',
  warming_up: 'unknown',
  not_connected: 'unknown',
};

export const MONEY_TONES: Record<MoneyState, Tone> = {
  reconciling: 'ok',
  provider_not_connected: 'unknown',
  store_not_connected: 'unknown',
  store_data_stale: 'unknown',
};

export const PAYMENT_ATTEMPTS_TONES: Record<PaymentAttemptsState, Tone> = {
  observing: 'ok',
  no_recent_attempts: 'unknown',
  no_attempts_seen: 'unknown',
  store_data_stale: 'unknown',
  store_not_connected: 'unknown',
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
};
