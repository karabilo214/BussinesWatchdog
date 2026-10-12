import { request } from '@bw/api-client';

export type StaffRole = 'platform_owner' | 'platform_admin' | 'platform_support' | 'platform_billing';

export interface StaffMe {
  staff_id: string;
  name: string;
  email: string;
  locale: string;
  role: StaffRole;
  mfa_enabled: true;
  capabilities: string[];
  billing_mode: 'test' | 'live';
  session: { idle_minutes: number; absolute_hours: number };
}

export async function fetchMe(): Promise<StaffMe> {
  return (await request<StaffMe>('GET', '/platform-api/v1/me')).data;
}

export async function login(email: string, password: string): Promise<void> {
  await request<{ mfa_required: true }>('POST', '/platform-api/v1/auth/login', { body: { email, password } });
}

export async function submitMfa(code: string): Promise<StaffMe> {
  return (await request<StaffMe>('POST', '/platform-api/v1/auth/mfa', { body: { code } })).data;
}

export async function logout(): Promise<void> {
  await request<null>('POST', '/platform-api/v1/auth/logout');
}

export type PlanState = 'draft' | 'published' | 'retired';
export type Interval = 'day' | 'week' | 'month' | 'year';
export type Localized = Partial<Record<'ru' | 'en' | 'de', string>>;

export interface PlanPrice {
  id: string;
  kind: 'base' | 'extra_store';
  mode: 'test' | 'live';
  currency: string;
  currency_exponent: number;
  amount_minor: string;
  interval: Interval;
  interval_count: number;
  state: PlanState;
  at_provider: boolean;
}

export interface PlanEntitlements {
  features: string[];
  limits: Record<string, number | null>;
}

export interface PlanVersion {
  id: string;
  plan_id?: string;
  version: number;
  state: PlanState;
  name: Localized;
  description: Localized;
  placeholder: boolean;
  trial_days: number;
  entitlements: PlanEntitlements;
  published_at: string | null;
  subscribers: number;
  prices: PlanPrice[];
}

export interface Plan {
  id: string;
  code: string;
  state: PlanState;
  versions: PlanVersion[];
}

export interface PlanCatalog {
  data: Plan[];
  mode: 'test' | 'live';
  provider_configured: boolean;
  catalog: {
    features: string[];
    limits: string[];
    per_store_limits: string[];
    currencies: string[];
    intervals: Interval[];
    max_interval_count: number;
    max_trial_days: number;
  };
}

export interface NewPrice {
  kind: PlanPrice['kind'];
  currency: string;
  amount_minor: string;
  interval: Interval;
  interval_count: number;
}

export async function fetchPlans(): Promise<PlanCatalog> {
  return (await request<PlanCatalog>('GET', '/platform-api/v1/plans')).data;
}

export async function createPlan(code: string, name: Localized): Promise<PlanVersion> {
  return (await request<PlanVersion>('POST', '/platform-api/v1/plans', { body: { code, name } })).data;
}

export async function createVersion(planId: string): Promise<PlanVersion> {
  return (await request<PlanVersion>('POST', `/platform-api/v1/plans/${encodeURIComponent(planId)}/versions`)).data;
}

export async function updateVersion(
  versionId: string,
  changes: Partial<{ name: Localized; description: Localized; trial_days: number; entitlements: PlanEntitlements }>,
): Promise<PlanVersion> {
  return (await request<PlanVersion>('PATCH', `/platform-api/v1/plan-versions/${encodeURIComponent(versionId)}`, { body: changes })).data;
}

export async function addPrice(versionId: string, price: NewPrice): Promise<PlanVersion> {
  return (await request<PlanVersion>('POST', `/platform-api/v1/plan-versions/${encodeURIComponent(versionId)}/prices`, { body: price })).data;
}

export async function removePrice(priceId: string): Promise<void> {
  await request<null>('DELETE', `/platform-api/v1/plan-prices/${encodeURIComponent(priceId)}`);
}

export async function publishVersion(versionId: string, reason: string, code: string): Promise<PlanVersion> {
  return (await request<PlanVersion>('POST', `/platform-api/v1/plan-versions/${encodeURIComponent(versionId)}/publish`, { body: { reason, code } })).data;
}

export async function retireVersion(versionId: string, reason: string, code: string): Promise<PlanVersion> {
  return (await request<PlanVersion>('POST', `/platform-api/v1/plan-versions/${encodeURIComponent(versionId)}/retire`, { body: { reason, code } })).data;
}
