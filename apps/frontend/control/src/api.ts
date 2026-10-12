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
  order_volume_grace_days: number;
  contact_sales: boolean;
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
  changes: Partial<{ name: Localized; description: Localized; trial_days: number; order_volume_grace_days: number; contact_sales: boolean; entitlements: PlanEntitlements }>,
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

export type DiscountAudience = 'code' | 'tenant' | 'automatic';
export type DiscountDuration = 'once' | 'periods' | 'forever';

export interface Discount {
  id: string;
  name: string;
  label: Localized;
  audience: DiscountAudience;
  code: string | null;
  kind: 'percentage' | 'flat';
  percent: string | null;
  amount_minor: string | null;
  currency: string | null;
  currency_exponent: number | null;
  duration: DiscountDuration;
  duration_periods: number | null;
  usage_limit: number | null;
  used: number;
  plan_ids: string[];
  tenants: { id: string; name: string }[];
  starts_at: string | null;
  expires_at: string | null;
  state: 'active' | 'archived';
  mode: 'test' | 'live';
  created_at: string | null;
}

export interface NewDiscount {
  name: string;
  label: Localized;
  audience: DiscountAudience;
  code: string | null;
  kind: Discount['kind'];
  percent_bps: number | null;
  amount_minor: string | null;
  currency: string | null;
  duration: DiscountDuration;
  duration_periods: number | null;
  usage_limit: number | null;
  plan_ids: string[];
  tenant_ids: string[];
  starts_at: string | null;
  expires_at: string | null;
}

export interface Confirmation {
  reason: string;
  confirmation_code: string;
}

export async function fetchDiscounts(): Promise<{ data: Discount[]; mode: 'test' | 'live' }> {
  return (await request<{ data: Discount[]; mode: 'test' | 'live' }>('GET', '/platform-api/v1/discounts')).data;
}

export async function createDiscount(discount: NewDiscount, confirmation: Confirmation): Promise<Discount> {
  return (await request<Discount>('POST', '/platform-api/v1/discounts', { body: { ...discount, ...confirmation } })).data;
}

export async function updateDiscount(
  id: string,
  changes: Partial<Pick<NewDiscount, 'usage_limit' | 'expires_at' | 'tenant_ids' | 'plan_ids'>>,
  confirmation: Confirmation,
): Promise<Discount> {
  return (await request<Discount>('PATCH', `/platform-api/v1/discounts/${encodeURIComponent(id)}`, { body: { ...changes, ...confirmation } })).data;
}

export async function archiveDiscount(id: string, confirmation: Confirmation): Promise<Discount> {
  return (await request<Discount>('POST', `/platform-api/v1/discounts/${encodeURIComponent(id)}/archive`, { body: confirmation })).data;
}

export async function lookupTenants(query: string): Promise<{ id: string; name: string }[]> {
  return (await request<{ data: { id: string; name: string }[] }>('GET', '/platform-api/v1/tenants/lookup', { query: { query } })).data.data;
}

export interface DiscountApplication {
  id: string;
  period_number: number;
  period_start: string | null;
  currency: string;
  currency_exponent: number;
  amount_before_minor: string;
  saving_minor: string;
  amount_after_minor: string;
  status: 'planned' | 'applied' | 'failed';
  error_code: string | null;
  tenant: { id: string; name: string };
  created_at: string;
  applied_at: string | null;
  lines: {
    discount: { id: string; name: string; code: string | null; audience: DiscountAudience } | null;
    position: number;
    amount_before_minor: string;
    saving_minor: string;
    amount_after_minor: string;
  }[];
}

export async function fetchDiscountApplications(cursor?: string): Promise<{ data: DiscountApplication[]; next_cursor: string | null }> {
  return (await request<{ data: DiscountApplication[]; next_cursor: string | null }>('GET', '/platform-api/v1/discount-applications', { query: { cursor } })).data;
}

export type AccessState = 'pilot' | 'active' | 'override' | 'trial' | 'grace' | 'paused_billing' | 'paused_manual' | 'no_service' | 'onboarding';
export type SubscriptionStatus = 'trial' | 'active' | 'past_due' | 'suspended' | 'cancelled';

export interface TenantAccess {
  state: AccessState;
  reasons: string[];
  enforced?: boolean;
}

export interface StoreCapacity {
  limit: number | null;
  stores: number;
  paused: number;
  choice_required: boolean;
}

export interface Contact {
  name: string | null;
  email: string | null;
  email_verified: boolean;
}

export interface SubscriptionSummary {
  id: string;
  provider: string;
  plan_code: string;
  plan_version_id: string | null;
  status: SubscriptionStatus;
  paid_until: string | null;
  trial_ends_at: string | null;
  period_end: string | null;
  past_due_since: string | null;
  cancel_at_period_end: boolean;
  extra_store_quantity: number;
}

export interface TenantRow {
  id: string;
  name: string;
  status: 'active' | 'suspended' | 'deleting';
  created_at: string | null;
  owner: Contact;
  access: TenantAccess;
  subscription: SubscriptionSummary | null;
  stores: StoreCapacity;
  open_incidents: number;
  override_active: boolean;
}

export interface PlanChangeEntry {
  id: string;
  kind: 'immediate' | 'next_period';
  status: string;
  from: { plan_code: string | null; extra_store_quantity: number };
  to: { plan_code: string | null; extra_store_quantity: number };
  currency: string;
  currency_exponent: number;
  due_now_minor: string;
  effective_at: string | null;
  created_at?: string | null;
}

export interface Override {
  id: string;
  entitlements: { features: string[]; limits: Record<string, number | null> };
  reason: string;
  starts_at: string;
  ends_at: string;
  revoked_at: string | null;
  active: boolean;
}

export interface TenantCard {
  id: string;
  name: string;
  status: TenantRow['status'];
  locale: string;
  timezone: string;
  created_at: string | null;
  trial_consumed_at: string | null;
  access: TenantAccess;
  capacity: StoreCapacity;
  members: (Contact & { role: string; joined_at: string | null })[];
  stores: { id: string; name: string; host: string; platform: string; status: string; verified: boolean; browser_enabled: boolean; capacity_paused_at: string | null; open_incidents: number; orders_this_month: number; orders_average: number | null; orders_over_since: string | null; created_at: string | null }[];
  orders_limit?: number | null;
  billing: {
    mode: 'test' | 'live';
    subscription: (SubscriptionSummary & { provider_subscription_ref: string | null; next_charge_at: string | null; scheduled_change: PlanChangeEntry | null }) | null;
    contracts: { id: string; provider: string; provider_subscription_ref: string | null; mode: string; state: string; provider_state: string | null; plan_code: string | null; extra_store_quantity: number; period_start: string | null; period_end: string | null; paid_until: string | null; cancel_at_period_end: boolean; problem_code: string | null }[];
    plan_changes: PlanChangeEntry[];
    overrides: Override[];
  } | null;
  audit: { id: string; action: string; actor: string | null; reason: string | null; result: string; changes: Record<string, unknown> | null; created_at: string }[];
}

export type TenantAction = 'suspend' | 'resume' | 'grant_override' | 'revoke_override' | 'extend_trial' | 'cancel_subscription' | 'refresh_billing';

export interface TenantActionInput {
  plan_version_id?: string;
  extra_stores?: number;
  ends_at?: string;
  override_id?: string;
  trial_ends_at?: string;
}

export interface ActionPreview {
  action: TenantAction;
  access_before: TenantAccess;
  access_after: TenantAccess;
  stores: number;
  effective_at: string | null;
}

export interface SubscriptionRow extends SubscriptionSummary {
  tenant: { id: string; name: string; status: string };
  access: TenantAccess;
  next_charge_at: string | null;
  provider_subscription_ref: string | null;
  mode: string | null;
}

export type TenantFilters = Partial<Record<'q' | 'tenant_status' | 'subscription' | 'plan' | 'cancel_scheduled' | 'trial_ends_before' | 'cursor', string>>;

export async function fetchTenants(filters: TenantFilters): Promise<{ data: TenantRow[]; next_cursor: string | null }> {
  return (await request<{ data: TenantRow[]; next_cursor: string | null }>('GET', '/platform-api/v1/tenants', { query: filters })).data;
}

export async function fetchTenant(id: string): Promise<TenantCard> {
  return (await request<TenantCard>('GET', `/platform-api/v1/tenants/${encodeURIComponent(id)}`)).data;
}

export async function previewTenantAction(id: string, action: TenantAction, input: TenantActionInput): Promise<ActionPreview> {
  return (await request<ActionPreview>('POST', `/platform-api/v1/tenants/${encodeURIComponent(id)}/actions/${action}/preview`, { body: input })).data;
}

export async function applyTenantAction(id: string, action: TenantAction, input: TenantActionInput, confirmation: Confirmation): Promise<TenantCard> {
  return (await request<TenantCard>('POST', `/platform-api/v1/tenants/${encodeURIComponent(id)}/actions/${action}`, { body: { ...input, ...confirmation } })).data;
}

export async function fetchSubscriptions(filters: Partial<Record<'status' | 'plan' | 'cancel_scheduled' | 'cursor', string>>): Promise<{ data: SubscriptionRow[]; next_cursor: string | null }> {
  return (await request<{ data: SubscriptionRow[]; next_cursor: string | null }>('GET', '/platform-api/v1/subscriptions', { query: filters })).data;
}

export type SiteLocale = 'ru' | 'en' | 'de';
export type PageKind = 'home' | 'pricing' | 'contact' | 'page';
export type PageMenu = 'none' | 'header' | 'footer';

export interface PageSummary {
  id: string;
  kind: PageKind;
  parent_id: string | null;
  slug: string | null;
  path: string;
  menu: PageMenu;
  sort: number;
  created_at: string | null;
  locales: Record<SiteLocale, { published: string | null; draft: boolean; title: string | null }>;
}

export interface PageContent {
  id: string;
  version: number;
  title: string;
  description: string | null;
  body: string;
  content_hash: string;
  updated_at: string | null;
  published_at: string | null;
}

export interface PageVersionRow {
  id: string;
  locale: SiteLocale;
  version: number;
  state: 'published' | 'retired';
  title: string;
  published_at: string | null;
  retired_at: string | null;
  published_by: string | null;
}

export interface PageCard extends PageSummary {
  content: Record<SiteLocale, { draft: PageContent | null; published: PageContent | null }>;
  history: PageVersionRow[];
}

export interface PageDraft {
  title: string;
  description: string | null;
  body: string;
}

export async function fetchPages(): Promise<PageSummary[]> {
  return (await request<{ data: PageSummary[] }>('GET', '/platform-api/v1/pages')).data.data;
}

export async function createPage(input: { slug: string; parent_id: string | null; menu: PageMenu; sort: number }): Promise<PageCard> {
  return (await request<PageCard>('POST', '/platform-api/v1/pages', { body: input })).data;
}

export async function fetchPage(id: string): Promise<PageCard> {
  return (await request<PageCard>('GET', `/platform-api/v1/pages/${encodeURIComponent(id)}`)).data;
}

export async function updatePage(id: string, input: { menu: PageMenu; sort: number }): Promise<PageCard> {
  return (await request<PageCard>('PATCH', `/platform-api/v1/pages/${encodeURIComponent(id)}`, { body: input })).data;
}

export async function savePageDraft(id: string, locale: SiteLocale, draft: PageDraft): Promise<PageCard> {
  return (await request<PageCard>('PUT', `/platform-api/v1/pages/${encodeURIComponent(id)}/locales/${locale}/draft`, { body: draft })).data;
}

export async function discardPageDraft(id: string, locale: SiteLocale): Promise<PageCard> {
  return (await request<PageCard>('DELETE', `/platform-api/v1/pages/${encodeURIComponent(id)}/locales/${locale}/draft`)).data;
}

export async function publishPage(id: string, locale: SiteLocale, reason: string): Promise<PageCard> {
  return (await request<PageCard>('POST', `/platform-api/v1/pages/${encodeURIComponent(id)}/locales/${locale}/publish`, { body: { reason } })).data;
}

export async function unpublishPage(id: string, locale: SiteLocale, reason: string): Promise<PageCard> {
  return (await request<PageCard>('POST', `/platform-api/v1/pages/${encodeURIComponent(id)}/locales/${locale}/unpublish`, { body: { reason } })).data;
}

export async function restorePageVersion(id: string, versionId: string): Promise<PageCard> {
  return (await request<PageCard>('POST', `/platform-api/v1/pages/${encodeURIComponent(id)}/versions/${encodeURIComponent(versionId)}/restore`)).data;
}

export async function archivePage(id: string, reason: string): Promise<void> {
  await request<null>('POST', `/platform-api/v1/pages/${encodeURIComponent(id)}/archive`, { body: { reason } });
}

export async function previewPage(id: string, locale: SiteLocale, draft: PageDraft): Promise<{ body: string; html: string }> {
  return (await request<{ body: string; html: string }>('POST', `/platform-api/v1/pages/${encodeURIComponent(id)}/preview`, { body: { locale, ...draft } })).data;
}

export type ContactStatus = 'new' | 'in_progress' | 'closed';

export interface ContactRequest {
  id: string;
  locale: SiteLocale;
  name: string;
  email: string;
  company: string | null;
  message: string;
  status: ContactStatus;
  note: string | null;
  handled_by: string | null;
  created_at: string | null;
  updated_at: string | null;
  closed_at: string | null;
}

export async function fetchContactRequests(filters: Partial<Record<'status' | 'cursor', string>>): Promise<{ data: ContactRequest[]; next_cursor: string | null; counts: Record<ContactStatus, number> }> {
  return (await request<{ data: ContactRequest[]; next_cursor: string | null; counts: Record<ContactStatus, number> }>('GET', '/platform-api/v1/contact-requests', { query: filters })).data;
}

export async function fetchContactRequest(id: string): Promise<ContactRequest> {
  return (await request<ContactRequest>('GET', `/platform-api/v1/contact-requests/${encodeURIComponent(id)}`)).data;
}

export async function updateContactRequest(id: string, input: { status?: ContactStatus; note?: string | null }): Promise<ContactRequest> {
  return (await request<ContactRequest>('PATCH', `/platform-api/v1/contact-requests/${encodeURIComponent(id)}`, { body: input })).data;
}
