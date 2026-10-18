import { request } from '@bw/api-client';

export type StaffRole = 'platform_owner' | 'platform_admin' | 'platform_support' | 'platform_billing';

export interface PublicStorefrontCheckHistory {
  id: string;
  url: string;
  created_at: string;
  started_at: string | null;
  finished_at: string | null;
  ip: string | null;
  user_agent: string | null;
  worker_browser: string | null;
  status: string;
  verdict: string | null;
  error_code: string | null;
}

export async function fetchPublicStorefrontChecks(cursor?: string): Promise<{ data: PublicStorefrontCheckHistory[]; next_cursor: string | null }> {
  return (await request<{ data: PublicStorefrontCheckHistory[]; next_cursor: string | null }>('GET', '/platform-api/v1/public-storefront-checks', { query: cursor ? { cursor } : {} })).data;
}

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
  kind: 'base' | 'extra_store' | 'faster_checks';
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
  faster_check_interval_minutes: number | null;
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
  changes: Partial<{ name: Localized; description: Localized; trial_days: number; order_volume_grace_days: number; contact_sales: boolean; faster_check_interval_minutes: number | null; entitlements: PlanEntitlements }>,
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
  members: (Contact & { user_id: string; role: string; mfa_enabled: boolean; joined_at: string | null })[];
  stores: { id: string; name: string; host: string; platform: string; status: string; verified: boolean; browser_enabled: boolean; capacity_paused_at: string | null; open_incidents: number; orders_this_month: number; orders_average: number | null; orders_over_since: string | null; created_at: string | null }[];
  orders_limit?: number | null;
  billing: {
    mode: 'test' | 'live';
    subscription: (SubscriptionSummary & { provider_subscription_ref: string | null; next_charge_at: string | null; scheduled_change: PlanChangeEntry | null }) | null;
    contracts: { id: string; provider: string; provider_subscription_ref: string | null; mode: string; state: string; provider_state: string | null; plan_code: string | null; extra_store_quantity: number; period_start: string | null; period_end: string | null; paid_until: string | null; cancel_at_period_end: boolean; problem_code: string | null }[];
    plan_changes: PlanChangeEntry[];
    overrides: Override[];
    transactions?: Payment[];
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
export type PolicyType = 'service_terms' | 'privacy_notice' | 'recurring_payment';

export interface PageSummary {
  id: string;
  kind: PageKind;
  parent_id: string | null;
  slug: string | null;
  path: string;
  menu: PageMenu;
  sort: number;
  policy_type: PolicyType | null;
  editable: boolean;
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
  builtin: Record<SiteLocale, PageDraft | null> | null;
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

export type PageBlockCategory = 'hero' | 'features' | 'steps' | 'pricing' | 'faq' | 'cta' | 'text' | 'other';

export interface PageBlock {
  id: string;
  category: PageBlockCategory;
  name: string;
  bodies: Partial<Record<SiteLocale, string>>;
  sort: number;
  updated_at: string | null;
}

export interface CustomerStore {
  id: string;
  name: string;
  base_url: string;
  platform: string;
  status: string;
  default_currency: string;
  timezone: string;
  verified_at?: string | null;
  [key: string]: unknown;
}

export interface Page<T> {
  data: T[];
  next_cursor: string | null;
}

export interface StoreFinding {
  id: string;
  order_id: string | null;
  order_display_number?: string | null;
  rule_code: string;
  status: string;
  reason_code: string | null;
  currency: string | null;
  currency_exponent: number | null;
  difference_minor: string | null;
  evaluated_at: string | null;
}

export interface StoreOrder {
  id: string;
  display_number: string | null;
  status: string;
  gateway: string | null;
  mode: string;
  currency: string;
  currency_exponent: number;
  total_minor: string;
  paid_marked_at: string | null;
  is_synthetic: boolean;
  source_created_at: string | null;
}

export interface StoreOrderDetail extends StoreOrder {
  transaction_ref: string | null;
  financial_support: string;
  findings: StoreFinding[];
  captures: { id: string; kind: string; status: string; currency: string; currency_exponent: number; amount_minor: string; occurred_at: string | null }[];
  refunds: { id: string; status: string; currency: string; currency_exponent: number; amount_minor: string; occurred_at: string | null }[];
  revisions: unknown[];
}

export interface StoreCheck {
  id: string;
  trigger: string;
  status: string;
  error_code: string | null;
  scheduled_at: string;
  started_at: string | null;
  finished_at: string | null;
}

export interface StoreCheckDetail extends StoreCheck {
  attempts: {
    id: string;
    attempt_number: number;
    status: string;
    error_code: string | null;
    started_at: string;
    finished_at: string | null;
    artifacts: { id: string; kind: string; content_type: string; size_bytes: number; expires_at: string }[];
    steps: { index: number; code: string; status: string; error_code: string | null }[];
  }[];
}

export interface StoreIncident {
  id: string;
  family: string;
  state: string;
  severity: string;
  title_code: string;
  currency: string | null;
  currency_exponent: number | null;
  verified_discrepancy_minor: string | null;
  first_seen_at: string | null;
  last_seen_at: string | null;
  resolved_at: string | null;
}

export interface NewCustomerStore {
  reason: string;
  name: string;
  base_url: string;
  timezone: string;
  default_currency: string;
  locale: 'ru' | 'en' | 'de';
  platform: 'woocommerce' | 'shopware';
}

function tenantPath(tenantId: string, path: string): string {
  return `/platform-api/v1/tenants/${encodeURIComponent(tenantId)}/${path}`;
}

function paged(cursor?: string | null): string {
  return cursor ? `?cursor=${encodeURIComponent(cursor)}` : '';
}

export async function createCustomerStore(tenantId: string, input: NewCustomerStore): Promise<CustomerStore> {
  return (await request<CustomerStore>('POST', tenantPath(tenantId, 'stores'), { body: input })).data;
}

export async function fetchCustomerStore(tenantId: string, storeId: string): Promise<CustomerStore> {
  return (await request<CustomerStore>('GET', tenantPath(tenantId, `stores/${encodeURIComponent(storeId)}`))).data;
}

export async function fetchStoreFindings(tenantId: string, storeId: string, cursor?: string | null): Promise<Page<StoreFinding>> {
  return (await request<Page<StoreFinding>>('GET', tenantPath(tenantId, `stores/${encodeURIComponent(storeId)}/findings${paged(cursor)}`))).data;
}

export async function fetchStoreOrders(tenantId: string, storeId: string, cursor?: string | null): Promise<Page<StoreOrder>> {
  return (await request<Page<StoreOrder>>('GET', tenantPath(tenantId, `stores/${encodeURIComponent(storeId)}/orders${paged(cursor)}`))).data;
}

export async function fetchStoreChecks(tenantId: string, storeId: string, cursor?: string | null): Promise<Page<StoreCheck>> {
  return (await request<Page<StoreCheck>>('GET', tenantPath(tenantId, `stores/${encodeURIComponent(storeId)}/checks${paged(cursor)}`))).data;
}

export async function fetchStoreIncidents(tenantId: string, storeId: string, cursor?: string | null): Promise<Page<StoreIncident>> {
  return (await request<Page<StoreIncident>>('GET', tenantPath(tenantId, `stores/${encodeURIComponent(storeId)}/incidents${paged(cursor)}`))).data;
}

export async function fetchCustomerOrder(tenantId: string, orderId: string): Promise<StoreOrderDetail> {
  return (await request<StoreOrderDetail>('GET', tenantPath(tenantId, `orders/${encodeURIComponent(orderId)}`))).data;
}

export async function fetchCustomerCheck(tenantId: string, checkId: string): Promise<StoreCheckDetail> {
  return (await request<StoreCheckDetail>('GET', tenantPath(tenantId, `checks/${encodeURIComponent(checkId)}`))).data;
}

export async function fetchCustomerArtifactUrl(tenantId: string, artifactId: string): Promise<{ url: string; content_type: string }> {
  return (await request<{ url: string; content_type: string }>('GET', tenantPath(tenantId, `artifacts/${encodeURIComponent(artifactId)}/download`))).data;
}

export type InvitableRole = 'admin' | 'operator' | 'viewer';

export interface TenantInvitation {
  id: string;
  email: string;
  role: InvitableRole;
  expires_at: string;
  created_at: string | null;
  revoked_at: string | null;
}

export async function fetchTenantInvitations(tenantId: string): Promise<TenantInvitation[]> {
  return (await request<{ data: TenantInvitation[] }>('GET', tenantPath(tenantId, 'invitations'))).data.data;
}

export async function inviteTenantMember(tenantId: string, input: { email: string; role: InvitableRole; reason: string }): Promise<TenantInvitation> {
  return (await request<TenantInvitation>('POST', tenantPath(tenantId, 'invitations'), { body: input })).data;
}

export async function revokeTenantInvitation(tenantId: string, invitationId: string, reason: string): Promise<TenantInvitation> {
  return (await request<TenantInvitation>('POST', tenantPath(tenantId, `invitations/${encodeURIComponent(invitationId)}/revoke`), { body: { reason } })).data;
}

export async function resetCustomerMfa(tenantId: string, userId: string, reason: string, confirmationCode: string): Promise<void> {
  await request('POST', tenantPath(tenantId, `members/${encodeURIComponent(userId)}/mfa/reset`), { body: { reason, confirmation_code: confirmationCode } });
}

export interface BusinessOverview {
  mode: 'live' | 'test';
  from: string;
  to: string;
  previous: { from: string; to: string };
  as_of: string;
  last_synced_at: string | null;
  customers: { paying: number; trial: number; past_due: number; cancel_scheduled: number };
  mrr: { currency: string; currency_exponent: number; numerator_minor: string; denominator: string; contracts: number; discounted_contracts: number; unpriced_contracts: number }[];
  money: OverviewMoney[];
  money_previous: OverviewMoney[];
  churn: { ended: number; at_start: number };
  churn_previous: { ended: number; at_start: number };
}

export interface OverviewMoney {
  currency: string;
  currency_exponent: number;
  collected_minor: string;
  collected_tax_minor: string;
  collections: number;
  refunded_minor: string;
  refunds: number;
  net_minor: string;
}

export async function fetchOverview(filters: { mode: 'live' | 'test'; from?: string; to?: string }): Promise<BusinessOverview> {
  return (await request<BusinessOverview>('GET', '/platform-api/v1/overview', { query: filters })).data;
}

export type HealthState = 'ok' | 'warn' | 'crit';

export interface HealthQueue {
  waiting: number;
  oldest_age_seconds: number | null;
  problems: number;
  state: HealthState;
  last_received_at?: string | null;
}

export interface ServiceHealth {
  as_of: string;
  state: HealthState;
  components: Record<string, HealthState>;
  queues: Record<'event_inbox' | 'domain_outbox' | 'billing_events' | 'reconciliation' | 'notifications' | 'jobs', HealthQueue>;
  browser_checks: HealthQueue & { running: number; workers_seen: number };
  connector_sync: { active: number; stale: number; state: HealthState };
  thresholds: { warn_seconds: number; crit_seconds: number; sync_stale_hours: number };
  version: { app: string; environment: string; php: string; laravel: string };
}

export async function fetchServiceHealth(): Promise<ServiceHealth> {
  return (await request<ServiceHealth>('GET', '/platform-api/v1/health')).data;
}

export interface StaffMember {
  id: string;
  name: string | null;
  email: string | null;
  role: StaffRole;
  state: 'active' | 'disabled';
  mfa_enabled: boolean;
  created_at: string | null;
  disabled_at: string | null;
  last_login_at: string | null;
}

export interface StaffInvitation {
  id: string;
  email: string;
  role: StaffRole;
  expires_at: string;
  created_at: string | null;
}

export interface StaffCatalog {
  staff: StaffMember[];
  invitations: StaffInvitation[];
}

export async function fetchStaff(): Promise<StaffCatalog> {
  return (await request<StaffCatalog>('GET', '/platform-api/v1/staff')).data;
}

export async function inviteStaff(input: { email: string; role: StaffRole; reason: string; confirmation_code: string }): Promise<StaffInvitation> {
  return (await request<StaffInvitation>('POST', '/platform-api/v1/staff/invitations', { body: input })).data;
}

export async function revokeStaffInvitation(id: string, reason: string): Promise<void> {
  await request<null>('POST', `/platform-api/v1/staff/invitations/${encodeURIComponent(id)}/revoke`, { body: { reason } });
}

export async function updateStaff(id: string, input: { role?: StaffRole; state?: 'active' | 'disabled'; reason: string; confirmation_code: string }): Promise<StaffCatalog> {
  return (await request<StaffCatalog>('PATCH', `/platform-api/v1/staff/${encodeURIComponent(id)}`, { body: input })).data;
}

export async function revokeStaffSessions(id: string, reason: string): Promise<void> {
  await request<null>('POST', `/platform-api/v1/staff/${encodeURIComponent(id)}/sessions/revoke`, { body: { reason } });
}

export async function lookupStaffInvitation(token: string): Promise<{ email: string; role: StaffRole; expires_at: string; account_exists: boolean }> {
  return (await request<{ email: string; role: StaffRole; expires_at: string; account_exists: boolean }>('GET', '/platform-api/v1/staff-invitations/lookup', { query: { token } })).data;
}

export async function startStaffInvitation(input: { token: string; name?: string; password: string }): Promise<{ mfa_enabled: boolean; otpauth_uri?: string; secret?: string; qr_svg?: string }> {
  return (await request<{ mfa_enabled: boolean; otpauth_uri?: string; secret?: string; qr_svg?: string }>('POST', '/platform-api/v1/staff-invitations/start', { body: input })).data;
}

export async function confirmStaffInvitation(input: { token: string; code: string }): Promise<{ staff_id: string; recovery_codes: string[] }> {
  return (await request<{ staff_id: string; recovery_codes: string[] }>('POST', '/platform-api/v1/staff-invitations/confirm', { body: input })).data;
}

export interface AuditEntry {
  id: string;
  created_at: string;
  actor: { id: string; name: string | null; email: string | null } | null;
  actor_kind: string;
  action: string;
  target_type: string;
  target_id: string;
  tenant: { id: string; name: string | null } | null;
  result: 'requested' | 'succeeded' | 'failed' | 'denied' | 'unknown';
  reason: string | null;
  changes: unknown;
  request_id: string;
}

export interface AuditPage {
  data: AuditEntry[];
  next_cursor: string | null;
  actions: string[];
  staff: { id: string; name: string | null }[];
}

export type AuditFilters = Partial<Record<'actor' | 'action' | 'result' | 'tenant' | 'from' | 'to' | 'cursor', string>>;

export async function fetchAudit(filters: AuditFilters): Promise<AuditPage> {
  return (await request<AuditPage>('GET', '/platform-api/v1/audit', { query: filters })).data;
}

export function auditExportUrl(filters: AuditFilters): string {
  const query = new URLSearchParams(Object.entries(filters).filter(([key, value]) => key !== 'cursor' && value)).toString();

  return `/platform-api/v1/audit/export${query ? `?${query}` : ''}`;
}

export interface SecretState {
  configured: boolean;
  fingerprint: string | null;
}

export interface MaintenanceNoticeRow {
  id: string;
  version: number;
  starts_at: string;
  ends_at: string;
  messages: Record<SiteLocale, string>;
  reason: string;
  created_by: string | null;
  created_at: string | null;
  cancelled_at: string | null;
  cancelled_by: string | null;
}

export interface PlatformSettingsView {
  configuration: {
    environment: string;
    release: string;
    app_url: string;
    billing: { enforcement: string; paddle_environment: string; paddle_account: string; api_key: SecretState; client_token: SecretState; webhook_secret: SecretState };
    mail: { mailer: string; from: string };
    telegram: { bot: string; token: SecretState; webhook_secret: SecretState };
    sessions: { idle_minutes: number; absolute_hours: number };
    retention: { screenshots_days: number; exports_days: number; contact_days: number };
    site: { contact_notify: number; cache_seconds: number };
  };
  maintenance: { current: MaintenanceNoticeRow | null; history: MaintenanceNoticeRow[]; limits: { max_hours: number; max_days_ahead: number } };
}

export async function fetchPlatformSettings(): Promise<PlatformSettingsView> {
  return (await request<PlatformSettingsView>('GET', '/platform-api/v1/settings')).data;
}

export async function scheduleMaintenance(input: { starts_at: string; ends_at: string; messages: Record<SiteLocale, string>; reason: string; confirmation_code: string }): Promise<PlatformSettingsView> {
  return (await request<PlatformSettingsView>('POST', '/platform-api/v1/settings/maintenance', { body: input })).data;
}

export async function cancelMaintenance(input: { reason: string; confirmation_code: string }): Promise<PlatformSettingsView> {
  return (await request<PlatformSettingsView>('POST', '/platform-api/v1/settings/maintenance/cancel', { body: input })).data;
}

export type ReminderTopic = 'subscription' | 'payment' | 'other';

export interface ReminderInput {
  topic: ReminderTopic;
  subject?: string;
  message?: string;
}

export interface ReminderPreview {
  recipients: { user_id: string; name: string; email: string; locale: string }[];
  letters: { locale: string; subject: string; body: string }[];
  last_sent_at: string | null;
}

export async function previewTenantReminder(tenantId: string, input: ReminderInput): Promise<ReminderPreview> {
  return (await request<ReminderPreview>('POST', `/platform-api/v1/tenants/${encodeURIComponent(tenantId)}/reminders/preview`, { body: input })).data;
}

export async function sendTenantReminder(tenantId: string, input: ReminderInput): Promise<{ topic: ReminderTopic; recipients: number }> {
  return (await request<{ topic: ReminderTopic; recipients: number }>('POST', `/platform-api/v1/tenants/${encodeURIComponent(tenantId)}/reminders`, { body: input })).data;
}

export interface SiteMedia {
  id: string;
  url: string;
  kind: 'image' | 'document';
  name: string;
  content_type: string;
  width: number | null;
  height: number | null;
  bytes: number;
  created_at: string;
}

export interface SiteMediaLimits {
  types: string[];
  extensions: string[];
  content_types: string[];
  max_bytes: number;
  max_side: number;
  max_pixels: number;
  resize_max_side: number;
}

export async function fetchSiteMedia(): Promise<{ data: SiteMedia[]; limits: SiteMediaLimits }> {
  return (await request<{ data: SiteMedia[]; limits: SiteMediaLimits }>('GET', '/platform-api/v1/site-media')).data;
}

export async function uploadSiteMedia(file: File): Promise<SiteMedia> {
  const body = new FormData();
  body.append('file', file);

  return (await request<SiteMedia>('POST', '/platform-api/v1/site-media', { body })).data;
}

export async function deleteSiteMedia(id: string): Promise<void> {
  await request<null>('POST', `/platform-api/v1/site-media/${encodeURIComponent(id)}/delete`);
}

export async function fetchPageBlocks(): Promise<PageBlock[]> {
  return (await request<{ data: PageBlock[] }>('GET', '/platform-api/v1/page-blocks')).data.data;
}

export interface PageBlockInput {
  name?: string;
  category?: PageBlockCategory;
  sort?: number;
  bodies?: Partial<Record<SiteLocale, string | null>>;
}

export async function fetchPageBlock(id: string): Promise<PageBlock> {
  return (await request<PageBlock>('GET', `/platform-api/v1/page-blocks/${encodeURIComponent(id)}`)).data;
}

export async function createPageBlock(input: PageBlockInput & { name: string; category: PageBlockCategory; bodies: Partial<Record<SiteLocale, string | null>> }): Promise<PageBlock> {
  return (await request<PageBlock>('POST', '/platform-api/v1/page-blocks', { body: input })).data;
}

export async function updatePageBlock(id: string, input: PageBlockInput): Promise<PageBlock> {
  return (await request<PageBlock>('PATCH', `/platform-api/v1/page-blocks/${encodeURIComponent(id)}`, { body: input })).data;
}

export async function archivePageBlock(id: string, reason: string): Promise<void> {
  await request<null>('POST', `/platform-api/v1/page-blocks/${encodeURIComponent(id)}/archive`, { body: { reason } });
}

export async function fetchPage(id: string): Promise<PageCard> {
  return (await request<PageCard>('GET', `/platform-api/v1/pages/${encodeURIComponent(id)}`)).data;
}

export async function updatePage(id: string, input: { menu: PageMenu; sort: number; policy_type?: PolicyType | null }): Promise<PageCard> {
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

export async function deletePageVersion(id: string, versionId: string, reason: string): Promise<PageCard> {
  return (await request<PageCard>('POST', `/platform-api/v1/pages/${encodeURIComponent(id)}/versions/${encodeURIComponent(versionId)}/delete`, { body: { reason } })).data;
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
  handled_by: string | null;
  created_at: string | null;
  updated_at: string | null;
  closed_at: string | null;
  notes?: ContactNote[];
  replies?: ContactReply[];
  reply_templates?: ContactReplyTemplate[];
}

export interface ContactNote {
  id: string;
  body: string;
  author: string | null;
  created_at: string | null;
}

export async function addContactNote(id: string, body: string): Promise<ContactRequest> {
  return (await request<ContactRequest>('POST', `/platform-api/v1/contact-requests/${encodeURIComponent(id)}/notes`, { body: { body } })).data;
}

export type ContactReplyTemplateCode = 'answer' | 'more_details' | 'resolved';

export interface ContactReplyTemplate {
  code: ContactReplyTemplateCode;
  subject: string;
  body: string;
}

export interface ContactReply {
  id: string;
  template: ContactReplyTemplateCode;
  subject: string;
  body: string;
  sent_by: string | null;
  sent_at: string;
}

export async function replyToContactRequest(id: string, input: { template: ContactReplyTemplateCode; subject: string; body: string }): Promise<ContactRequest> {
  return (await request<ContactRequest>('POST', `/platform-api/v1/contact-requests/${encodeURIComponent(id)}/replies`, { body: input })).data;
}

export async function fetchContactRequests(filters: Partial<Record<'status' | 'cursor', string>>): Promise<{ data: ContactRequest[]; next_cursor: string | null; counts: Record<ContactStatus, number> }> {
  return (await request<{ data: ContactRequest[]; next_cursor: string | null; counts: Record<ContactStatus, number> }>('GET', '/platform-api/v1/contact-requests', { query: filters })).data;
}

export async function fetchContactRequest(id: string): Promise<ContactRequest> {
  return (await request<ContactRequest>('GET', `/platform-api/v1/contact-requests/${encodeURIComponent(id)}`)).data;
}

export async function updateContactRequest(id: string, input: { status: ContactStatus }): Promise<ContactRequest> {
  return (await request<ContactRequest>('PATCH', `/platform-api/v1/contact-requests/${encodeURIComponent(id)}`, { body: input })).data;
}

export type PaymentStatus = 'draft' | 'ready' | 'billed' | 'paid' | 'completed' | 'canceled' | 'past_due';

export interface Payment {
  id: string;
  provider_transaction_ref: string;
  status: PaymentStatus;
  origin: string | null;
  invoice_number: string | null;
  currency: string;
  currency_exponent: number;
  subtotal_minor: string | null;
  discount_minor: string | null;
  tax_minor: string | null;
  total_minor: string | null;
  fee_minor: string | null;
  earnings_minor: string | null;
  refunded_minor?: string;
  period_start: string | null;
  period_end: string | null;
  billed_at: string | null;
  tenant?: { id: string; name: string };
}

export interface PaymentMonth {
  currency: string;
  currency_exponent: number;
  payments: number;
  total_minor: string;
  tax_minor: string | null;
  fee_minor: string | null;
  earnings_minor: string | null;
  refunded_minor?: string;
}

export async function fetchPayments(filters: Partial<Record<'status' | 'tenant_id' | 'cursor', string>>): Promise<{ data: Payment[]; next_cursor: string | null; month: PaymentMonth[]; mode: 'test' | 'live' }> {
  return (await request<{ data: Payment[]; next_cursor: string | null; month: PaymentMonth[]; mode: 'test' | 'live' }>('GET', '/platform-api/v1/payments', { query: filters })).data;
}

export type RefundStatus = 'requested' | 'rejected' | 'submitting' | 'pending' | 'refunded' | 'declined' | 'failed' | 'unknown';

export interface Refund {
  id: string;
  tenant: { id: string; name: string };
  transaction_id: string;
  provider_transaction_ref: string;
  invoice_number: string | null;
  kind: 'full' | 'partial';
  amount_minor: string;
  tax_minor: string | null;
  currency: string;
  currency_exponent: number;
  reason: string;
  cancel_subscription: boolean;
  status: RefundStatus;
  provider_status: string | null;
  error_code: string | null;
  requested_by: string | null;
  decided_by: string | null;
  decision_reason: string | null;
  created_at: string;
  decided_at: string | null;
}

export async function fetchRefunds(filters: Partial<Record<'status' | 'transaction_id', string>> = {}): Promise<Refund[]> {
  return (await request<{ data: Refund[] }>('GET', '/platform-api/v1/refunds', { query: filters })).data.data;
}

export async function requestRefund(paymentId: string, input: { amount_minor: string; reason: string; cancel_subscription: boolean }): Promise<{ id: string }> {
  return (await request<{ id: string }>('POST', `/platform-api/v1/payments/${encodeURIComponent(paymentId)}/refunds`, { body: input })).data;
}

export async function approveRefund(id: string, confirmation: Confirmation): Promise<{ id: string; status: RefundStatus }> {
  return (await request<{ id: string; status: RefundStatus }>('POST', `/platform-api/v1/refunds/${encodeURIComponent(id)}/approve`, { body: confirmation })).data;
}

export async function rejectRefund(id: string, reason: string): Promise<{ id: string; status: RefundStatus }> {
  return (await request<{ id: string; status: RefundStatus }>('POST', `/platform-api/v1/refunds/${encodeURIComponent(id)}/reject`, { body: { reason } })).data;
}
