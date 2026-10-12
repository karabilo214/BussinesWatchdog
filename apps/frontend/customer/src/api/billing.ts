import { newIdempotencyKey, request } from '@bw/api-client';

export type AccessState = 'pilot' | 'active' | 'override' | 'trial' | 'grace' | 'paused_billing' | 'paused_manual' | 'no_service' | 'onboarding';
export type SubscriptionStatus = 'trial' | 'active' | 'past_due' | 'suspended' | 'cancelled';
export type LimitCode = 'max_active_stores' | 'max_extra_stores' | 'min_check_interval_minutes' | 'retention_days' | 'monthly_event_budget' | 'manual_runs_per_day';
export type MeteredLimit = 'manual_runs_per_day' | 'monthly_event_budget';

export interface UsagePeriod {
  used: number;
  period_start: string;
  period_end: string;
}

export interface Entitlements {
  access_state: AccessState;
  reason_codes: string[];
  enforced: boolean;
  plan_code: string | null;
  features: { code: string; included: boolean; reason: string }[];
  limits: Partial<Record<LimitCode, number | null>>;
  usage: Record<MeteredLimit, UsagePeriod>;
}

export interface BillingPrice {
  id: string;
  kind: 'base' | 'extra_store';
  currency: string;
  currency_exponent: number;
  amount_minor: string;
  interval: string;
  interval_count: number;
  tax_behavior: string;
}

export interface BillingPlan {
  plan_code: string;
  plan_version_id: string;
  name: Record<string, string>;
  placeholder: boolean;
  trial_days: number;
  entitlements: { features: string[]; limits: Partial<Record<LimitCode, number | null>> };
  prices: BillingPrice[];
}

export interface BillingCatalog {
  data: BillingPlan[];
  mode: 'test' | 'live';
  checkout_available: boolean;
  trial_available: boolean;
}

export interface BillingSubscription {
  id: string;
  provider: string;
  plan_code: string;
  plan_version_id: string;
  status: SubscriptionStatus;
  access_state: AccessState;
  access_reason_codes: string[];
  paid_until: string | null;
  trial_ends_at: string | null;
  grace_until: string | null;
  period_end: string | null;
  cancel_at_period_end: boolean;
  extra_store_quantity: number;
  version: number;
  entitlements: { features: string[]; limits: Partial<Record<LimitCode, number | null>> };
}

export type CheckoutStatus = 'creating' | 'pending' | 'requires_action' | 'confirmed' | 'failed' | 'cancelled' | 'expired' | 'unknown';

export interface BillingCheckout {
  id: string;
  status: CheckoutStatus;
  error_code: string | null;
  expires_at: string;
  extra_store_quantity: number;
  access_state: AccessState;
  provider_checkout: { provider: string; environment: 'sandbox' | 'production'; client_token: string | null; transaction_id: string } | null;
}

export async function fetchEntitlements(): Promise<Entitlements> {
  return (await request<Entitlements>('GET', '/api/v1/entitlements')).data;
}

export async function fetchPlans(): Promise<BillingCatalog> {
  return (await request<BillingCatalog>('GET', '/api/v1/billing/plans')).data;
}

export async function fetchSubscription(): Promise<BillingSubscription | null> {
  return (await request<BillingSubscription | null>('GET', '/api/v1/billing/subscription')).data;
}

export async function startTrial(planVersionId: string): Promise<BillingSubscription> {
  return (await request<BillingSubscription>('POST', '/api/v1/billing/trial', { body: { plan_version_id: planVersionId }, idempotencyKey: newIdempotencyKey() })).data;
}

export async function startCheckout(priceId: string, extraStores: number, idempotencyKey: string): Promise<BillingCheckout> {
  return (await request<BillingCheckout>('POST', '/api/v1/billing/checkout', { body: { price_id: priceId, extra_stores: extraStores }, idempotencyKey })).data;
}

export async function fetchCheckout(id: string): Promise<BillingCheckout> {
  return (await request<BillingCheckout>('GET', `/api/v1/billing/checkouts/${encodeURIComponent(id)}`)).data;
}
