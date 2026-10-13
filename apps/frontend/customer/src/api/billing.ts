import { newIdempotencyKey, request } from '@bw/api-client';

export type AccessState = 'pilot' | 'active' | 'override' | 'trial' | 'grace' | 'paused_billing' | 'paused_manual' | 'no_service' | 'onboarding';
export type SubscriptionStatus = 'trial' | 'active' | 'past_due' | 'suspended' | 'cancelled';
export type LimitCode = 'max_active_stores' | 'max_extra_stores' | 'min_check_interval_minutes' | 'retention_days' | 'monthly_event_budget' | 'manual_runs_per_day' | 'max_orders_per_month';
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
  kind: 'base' | 'extra_store' | 'faster_checks';
  currency: string;
  currency_exponent: number;
  amount_minor: string;
  interval: string;
  interval_count: number;
  tax_behavior: string;
}

export interface BillingDiscount {
  id: string;
  position: 1 | 2 | 3;
  audience: 'automatic' | 'tenant' | 'code';
  label: Record<string, string>;
  kind: 'percentage' | 'flat';
  percent: string | null;
  amount_minor: string | null;
  currency: string | null;
  currency_exponent: number | null;
  duration: 'once' | 'periods' | 'forever';
  duration_periods: number | null;
  expires_at: string | null;
}

export interface DiscountBreakdown {
  id: string;
  period_number: number;
  currency: string;
  currency_exponent: number;
  amount_before_minor: string;
  saving_minor: string;
  amount_after_minor: string;
  status: 'planned' | 'applied' | 'failed';
  lines: { discount: BillingDiscount | null; position: number; amount_before_minor: string; saving_minor: string; amount_after_minor: string }[];
}

export interface BillingPlan {
  plan_code: string;
  plan_version_id: string;
  name: Record<string, string>;
  placeholder: boolean;
  trial_days: number;
  contact_sales?: boolean;
  faster_check_interval_minutes?: number | null;
  order_volume_grace_days?: number;
  entitlements: { features: string[]; limits: Partial<Record<LimitCode, number | null>> };
  prices: BillingPrice[];
  discounts: BillingDiscount[];
}

export interface PolicyDocument {
  type: 'service_terms' | 'privacy_notice' | 'recurring_payment';
  version_id: string;
  version: number;
  locale: string;
  title: string;
  url: string;
  published_at: string | null;
}

export interface BillingCatalog {
  data: BillingPlan[];
  mode: 'test' | 'live';
  checkout_available: boolean;
  trial_available: boolean;
  documents?: PolicyDocument[];
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
  faster_check_interval_minutes?: number | null;
  price: { id: string; amount_minor: string; currency: string; currency_exponent: number; billing_interval: string; billing_interval_count: number } | null;
  scheduled_change: PlanChange | null;
  capacity: StoreCapacity;
  order_volume?: OrderVolume;
  version: number;
  entitlements: { features: string[]; limits: Partial<Record<LimitCode, number | null>> };
}

export interface StoreCapacity {
  limit: number | null;
  stores: number;
  paused: number;
  choice_required: boolean;
}

export interface PlanChangeSide {
  plan_code: string | null;
  plan_version_id: string | null;
  price_id: string;
  amount_minor: string | null;
  extra_store_quantity: number;
  faster_checks?: boolean;
}

export interface PlanChange {
  id: string;
  preview_token: string | null;
  kind: 'immediate' | 'next_period';
  status: 'previewed' | 'applying' | 'applied' | 'scheduled' | 'failed' | 'unknown' | 'cancelled' | 'expired';
  from: PlanChangeSide;
  to: PlanChangeSide;
  currency: string;
  currency_exponent: number;
  due_now_minor: string;
  next_amount_before_minor: string;
  next_amount_minor: string;
  effective_at: string | null;
  expires_at: string;
  retained_store_ids: string[] | null;
  error_code: string | null;
}

export type CheckoutStatus = 'creating' | 'pending' | 'requires_action' | 'confirmed' | 'failed' | 'cancelled' | 'expired' | 'unknown';

export interface BillingCheckout {
  id: string;
  status: CheckoutStatus;
  error_code: string | null;
  expires_at: string;
  extra_store_quantity: number;
  discounts: DiscountBreakdown | null;
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

export async function startTrial(planVersionId: string, policyVersionIds: string[] = []): Promise<BillingSubscription> {
  return (await request<BillingSubscription>('POST', '/api/v1/billing/trial', { body: { plan_version_id: planVersionId, ...withPolicies(policyVersionIds) }, idempotencyKey: newIdempotencyKey() })).data;
}

export async function startCheckout(priceId: string, extraStores: number, idempotencyKey: string, discountCode: string | null = null, fasterChecks = false, policyVersionIds: string[] = []): Promise<BillingCheckout> {
  return (
    await request<BillingCheckout>('POST', '/api/v1/billing/checkout', {
      body: { price_id: priceId, extra_stores: extraStores, ...(discountCode === null ? {} : { discount_code: discountCode }), ...(fasterChecks ? { faster_checks: true } : {}), ...withPolicies(policyVersionIds) },
      idempotencyKey,
    })
  ).data;
}

export async function checkDiscount(code: string, priceId: string): Promise<BillingDiscount> {
  return (await request<BillingDiscount>('GET', '/api/v1/billing/discounts/check', { query: { code, price_id: priceId } })).data;
}

export async function fetchCheckout(id: string): Promise<BillingCheckout> {
  return (await request<BillingCheckout>('GET', `/api/v1/billing/checkouts/${encodeURIComponent(id)}`)).data;
}

export async function previewPlanChange(priceId: string, extraStores: number, retainedStoreIds: string[] | null, fasterChecks = false): Promise<PlanChange> {
  return (
    await request<PlanChange>('POST', '/api/v1/billing/plan-change-preview', {
      body: { price_id: priceId, extra_stores: extraStores, ...(retainedStoreIds === null ? {} : { retained_store_ids: retainedStoreIds }), ...(fasterChecks ? { faster_checks: true } : {}) },
    })
  ).data;
}

export async function confirmPlanChange(previewToken: string, idempotencyKey: string, policyVersionIds: string[] = []): Promise<{ change: PlanChange; subscription: BillingSubscription | null }> {
  return (await request<{ change: PlanChange; subscription: BillingSubscription | null }>('POST', '/api/v1/billing/plan-change', { body: { preview_token: previewToken, ...withPolicies(policyVersionIds) }, idempotencyKey })).data;
}

export async function withdrawPlanChange(): Promise<{ change: PlanChange; subscription: BillingSubscription | null }> {
  return (await request<{ change: PlanChange; subscription: BillingSubscription | null }>('POST', '/api/v1/billing/plan-change/cancel', { idempotencyKey: newIdempotencyKey() })).data;
}

export async function cancelSubscription(reason: string | null): Promise<BillingSubscription | null> {
  return (await request<BillingSubscription | null>('POST', '/api/v1/billing/cancel', { body: { at_period_end: true, reason }, idempotencyKey: newIdempotencyKey() })).data;
}

export async function resumeSubscription(expectedVersion: number): Promise<BillingSubscription | null> {
  return (await request<BillingSubscription | null>('POST', '/api/v1/billing/resume', { body: { expected_version: expectedVersion }, idempotencyKey: newIdempotencyKey() })).data;
}

export async function retainStores(storeIds: string[]): Promise<StoreCapacity> {
  return (await request<StoreCapacity>('PUT', '/api/v1/billing/retained-stores', { body: { store_ids: storeIds } })).data;
}

export interface OrderVolumeStore {
  store_id: string;
  name: string;
  this_month: number;
  average: number | null;
  months: { month: string; orders: number }[];
  over_since: string | null;
  grace_ends_at: string | null;
}

export interface OrderVolume {
  limit: number | null;
  grace_days: number;
  paused_at: string | null;
  stores: OrderVolumeStore[];
  recommended: { plan_code: string; name: Record<string, string>; contact_sales: boolean } | null;
}

function withPolicies(policyVersionIds: string[]): { policy_version_ids?: string[] } {
  return policyVersionIds.length === 0 ? {} : { policy_version_ids: policyVersionIds };
}

export interface BillingInvoice {
  id: string;
  status: 'billed' | 'paid' | 'completed' | 'canceled' | 'past_due';
  origin: string | null;
  invoice_number: string | null;
  currency: string;
  currency_exponent: number;
  subtotal_minor: string | null;
  discount_minor: string | null;
  tax_minor: string | null;
  total_minor: string | null;
  refunded_minor?: string;
  period_start: string | null;
  period_end: string | null;
  billed_at: string | null;
  invoice_available: boolean;
}

export async function listInvoices(cursor: string | null = null): Promise<{ data: BillingInvoice[]; next_cursor: string | null }> {
  return (await request<{ data: BillingInvoice[]; next_cursor: string | null }>('GET', '/api/v1/billing/invoices', { query: cursor === null ? {} : { cursor } })).data;
}

export async function invoiceLink(id: string): Promise<string> {
  return (await request<{ url: string }>('GET', `/api/v1/billing/invoices/${encodeURIComponent(id)}/download`)).data.url;
}

export async function paymentMethodCheckout(): Promise<{ provider: string; environment: 'sandbox' | 'production'; client_token: string | null; transaction_id: string }> {
  return (await request<{ provider_checkout: { provider: string; environment: 'sandbox' | 'production'; client_token: string | null; transaction_id: string } }>('POST', '/api/v1/billing/payment-method', { idempotencyKey: newIdempotencyKey() })).data.provider_checkout;
}
