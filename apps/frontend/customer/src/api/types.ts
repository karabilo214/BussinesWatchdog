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

export interface CreateStoreInput {
  name: string;
  base_url: string;
  timezone: string;
  locale: Locale;
  default_currency: string;
}

export type UpdateStoreInput = Partial<Pick<Store, 'name' | 'timezone' | 'default_currency' | 'browser_enabled' | 'telemetry_enabled'>> & {
  status?: 'active' | 'paused';
};

export type VerificationMethod = 'dns' | 'plugin_challenge';

export type VerificationInstructions =
  | { type: 'dns_txt'; record_name: string; txt_value: string }
  | { type: 'plugin_challenge'; url: string; body: string };

export interface StoreVerification {
  id: string;
  store_id: string;
  method: VerificationMethod;
  state: 'pending' | 'verified' | 'failed' | 'expired';
  verified_origin: string | null;
  expires_at: string | null;
  verified_at: string | null;
  reason_code: string | null;
  attempts: number;
  last_checked_at: string | null;
  instructions?: VerificationInstructions;
}

export interface PairingCode {
  id: string;
  store_id: string;
  pairing_code: string;
  expires_at: string;
  saas_endpoint: string;
  service_url: string;
}

export type IntegrationStatus = 'pending' | 'active' | 'degraded' | 'revoked' | 'disabled';

export interface Integration {
  id: string;
  store_id: string;
  provider: string;
  mode: 'live' | 'test';
  source_authority: 'store_reported' | 'independent_provider';
  status: IntegrationStatus;
  connector_version: string | null;
  last_heartbeat_at: string | null;
  created_at: string;
}

export type IncidentState = 'open' | 'acknowledged' | 'resolved';
export type IncidentSeverity = 'info' | 'warning' | 'critical';
export type IncidentFamily = 'money' | 'checkout' | 'checkout_payment' | 'integration';

export interface Incident {
  id: string;
  store_id: string;
  family: IncidentFamily;
  component: string;
  state: IncidentState;
  severity: IncidentSeverity;
  title_code: string;
  currency: string | null;
  currency_exponent: number | null;
  verified_discrepancy_minor: string | null;
  first_seen_at: string;
  last_seen_at: string;
  last_good_at: string | null;
  first_bad_at: string | null;
  acknowledged_by: string | null;
  acknowledged_at: string | null;
  resolved_at: string | null;
  resolution_reason: string | null;
  revision: number;
  created_at: string;
  updated_at: string;
}

export interface Signal {
  id: string;
  signal_type: 'reconciliation_finding' | 'payment_attempts' | 'connector_freshness' | 'browser_check';
  family: string;
  component: string;
  severity: IncidentSeverity;
  confidence: 'observed' | 'corroborated' | 'inferred' | 'unknown';
  currency: string | null;
  finding_id: string | null;
  evidence: Record<string, unknown>;
  detected_at: string;
}

export interface Finding {
  id: string;
  run_id: string;
  order_id: string | null;
  payment_id: string | null;
  rule_code: string;
  status: string;
  reason_code: string | null;
  currency: string;
  currency_exponent: number | null;
  expected_minor: string | null;
  actual_minor: string | null;
  difference_minor: string | null;
  evaluated_at: string;
  order_display_number?: string | null;
}

export interface IncidentActivity {
  id: string;
  incident_id: string;
  kind: 'created' | 'signal_linked' | 'acknowledged' | 'comment' | 'resolved' | 'reopened' | 'severity_changed' | 'suppressed';
  actor_id: string | null;
  incident_revision: number;
  data: Record<string, unknown>;
  created_at: string;
}

export interface Suppression {
  id: string;
  incident_id: string;
  reason: string;
  created_by: string | null;
  starts_at: string;
  ends_at: string;
  revoked_at: string | null;
  created_at: string;
}

export interface IncidentDetail extends Incident {
  signals: Signal[];
  findings: Finding[];
  activity: IncidentActivity[];
  active_suppression: Suppression | null;
}

export interface CheckArtifact {
  id: string;
  kind: string;
  content_type: string;
}

export interface CheckRun {
  id: string;
  store_id: string;
  scenario_id: string;
  status: string;
  error_code: string | null;
  finished_at: string | null;
  attempts: { id: string; status: string; artifacts: CheckArtifact[] }[];
}

export interface ArtifactLink {
  url: string;
  expires_at: string;
  content_type: string;
  size_bytes: number;
}
