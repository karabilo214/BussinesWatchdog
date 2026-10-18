import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api';
import type { StaffMe, TenantCard, TenantRow } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';

vi.mock('@/api', () => ({
  fetchMe: vi.fn(),
  login: vi.fn(),
  submitMfa: vi.fn(),
  logout: vi.fn(),
  fetchPlans: vi.fn(),
  fetchTenants: vi.fn(),
  fetchTenant: vi.fn(),
  previewTenantAction: vi.fn(),
  applyTenantAction: vi.fn(),
  previewTenantReminder: vi.fn(),
  sendTenantReminder: vi.fn(),
  createCustomerStore: vi.fn(),
  fetchCustomerStore: vi.fn(),
  fetchStoreFindings: vi.fn(),
  fetchStoreOrders: vi.fn(),
  fetchStoreChecks: vi.fn(),
  fetchStoreIncidents: vi.fn(),
  fetchCustomerOrder: vi.fn(),
  fetchCustomerCheck: vi.fn(),
  fetchCustomerArtifactUrl: vi.fn(),
  fetchTenantInvitations: vi.fn(),
  inviteTenantMember: vi.fn(),
  revokeTenantInvitation: vi.fn(),
  resetCustomerMfa: vi.fn(),
  fetchSubscriptions: vi.fn(),
}));

const mocked = vi.mocked(api);

function staff(capabilities: string[]): StaffMe {
  return { staff_id: 's-1', name: 'Founder', email: 'f@example.test', locale: 'ru', role: 'platform_owner', mfa_enabled: true, capabilities, billing_mode: 'test', session: { idle_minutes: 30, absolute_hours: 8 } };
}

const ROW: TenantRow = {
  id: 't-1',
  name: 'Kaffee Lindner GmbH',
  status: 'active',
  created_at: '2026-10-01T10:00:00Z',
  owner: { name: 'Anna', email: 'anna@example.test', email_verified: true },
  access: { state: 'active', reasons: ['paid_period'] },
  subscription: { id: 'sub-1', provider: 'paddle', plan_code: 'start', plan_version_id: 'v-1', status: 'active', paid_until: '2026-11-11T00:00:00Z', trial_ends_at: null, period_end: '2026-11-11T00:00:00Z', past_due_since: null, cancel_at_period_end: true, extra_store_quantity: 1 },
  stores: { limit: 2, stores: 3, paused: 0, choice_required: true },
  open_incidents: 2,
  override_active: false,
};

function card(overrides: Partial<TenantCard> = {}): TenantCard {
  return {
    id: 't-1',
    name: 'Kaffee Lindner GmbH',
    status: 'active',
    locale: 'de',
    timezone: 'Europe/Berlin',
    created_at: '2026-10-01T10:00:00Z',
    trial_consumed_at: null,
    access: { state: 'active', reasons: ['paid_period'], enforced: true },
    capacity: { limit: 2, stores: 1, paused: 0, choice_required: false },
    members: [{ user_id: 'u-1', role: 'owner', mfa_enabled: true, joined_at: '2026-10-01T10:00:00Z', name: 'Anna', email: 'anna@example.test', email_verified: true }],
    stores: [{ id: 's-1', name: 'Shop', host: 'kaffee.example', platform: 'woocommerce', status: 'active', verified: true, browser_enabled: true, capacity_paused_at: null, open_incidents: 1, orders_this_month: 640, orders_average: 1267, orders_over_since: '2026-10-20T04:10:00Z', created_at: null }], orders_limit: 1000,
    billing: {
      mode: 'test',
      subscription: { ...ROW.subscription!, cancel_at_period_end: false, provider_subscription_ref: 'sub_1', next_charge_at: '2026-11-11T00:00:00Z', scheduled_change: null },
      contracts: [{ id: 'c-1', provider: 'paddle', provider_subscription_ref: 'sub_1', mode: 'test', state: 'active', provider_state: 'active', plan_code: 'start', extra_store_quantity: 1, period_start: '2026-10-11T00:00:00Z', period_end: '2026-11-11T00:00:00Z', paid_until: '2026-11-11T00:00:00Z', cancel_at_period_end: false, problem_code: null }],
      plan_changes: [],
      overrides: [],
    },
    audit: [],
    ...overrides,
  };
}

async function open(path: string, capabilities = ['tenants.read', 'tenants.manage', 'billing.read', 'plans.read']) {
  mocked.fetchMe.mockResolvedValue(staff(capabilities));
  await useStaff().load(true);
  const router = buildRouter();
  await router.push(path);
  await router.isReady();
  const wrapper = mount(App, { global: { plugins: [localization, router] } });
  await flushPromises();
  await vi.waitFor(() => expect(wrapper.find('h1').exists()).toBe(true));
  await flushPromises();

  return { wrapper, router };
}

beforeEach(() => {
  vi.clearAllMocks();
  useStaff().forget();
  localization.setLocale('ru');
  mocked.fetchPlans.mockResolvedValue({ data: [{ id: 'p-1', code: 'start', state: 'published', versions: [{ id: 'v-1', version: 1, state: 'published', name: { ru: 'Старт' }, description: {}, placeholder: false, trial_days: 14, entitlements: { features: [], limits: {} }, published_at: null, subscribers: 1, prices: [] }] }], mode: 'test', provider_configured: true } as never);
  mocked.fetchTenants.mockResolvedValue({ data: [ROW], next_cursor: null });
  mocked.fetchTenant.mockResolvedValue(card());
});

describe('tenants', () => {
  it('lets support reset a customer authenticator with a reason and code', async () => {
    mocked.fetchTenant.mockResolvedValueOnce(card()).mockResolvedValueOnce(card({ members: [{ user_id: 'u-1', role: 'owner', mfa_enabled: false, joined_at: null, name: 'Anna', email: 'anna@example.test', email_verified: true }] }));
    mocked.resetCustomerMfa.mockResolvedValue();
    const { wrapper } = await open('/owner/tenants/t-1?tab=members', ['tenants.read', 'customers.mfa_reset']);
    await wrapper.get('[data-action="reset-mfa"]').trigger('click');
    const form = wrapper.get('[data-form="mfa-reset"]');
    const inputs = form.findAll('input');
    await inputs[0]!.setValue('Customer called');
    await inputs[1]!.setValue('123456');
    await form.trigger('submit');
    await flushPromises();

    expect(mocked.resetCustomerMfa).toHaveBeenCalledWith('t-1', 'u-1', 'Customer called', '123456');
    expect(wrapper.text()).toContain('MFA пользователя сброшена');
    expect(wrapper.find('[data-action="reset-mfa"]').exists()).toBe(false);
  });

  it('lists organisations with access and billing apart and keeps filters, not the search, in the address', async () => {
    const { wrapper, router } = await open('/owner/tenants');

    const row = wrapper.get('[data-tenant="Kaffee Lindner GmbH"]');
    expect(row.text()).toContain('Активен');
    expect(row.text()).toContain('Оплачена');
    expect(row.text()).toContain('Отмена в конце периода');
    expect(row.text()).toContain('3 / 2');
    expect(row.text()).toContain('Ждёт выбора магазинов');

    await wrapper.get('[data-field="search"]').setValue('anna@example.test');
    await wrapper.get('[data-field="subscription"]').setValue('trial');
    await wrapper.get('[data-field="cancel-scheduled"]').setValue(true);
    await wrapper.get('[data-form="tenant-filters"]').trigger('submit');
    await flushPromises();

    expect(mocked.fetchTenants).toHaveBeenLastCalledWith({ q: 'anna@example.test', subscription: 'trial', cancel_scheduled: '1' });
    expect(router.currentRoute.value.query).toEqual({ subscription: 'trial', cancel_scheduled: '1' });
  });

  it('previews an action, then confirms it with a reason and the authenticator code', async () => {
    mocked.previewTenantAction.mockResolvedValue({ action: 'suspend', access_before: { state: 'active', reasons: [] }, access_after: { state: 'paused_manual', reasons: [] }, stores: 1, effective_at: '2026-10-20T10:00:00Z' });
    mocked.applyTenantAction.mockResolvedValue(card({ status: 'suspended', access: { state: 'paused_manual', reasons: ['tenant_suspended'], enforced: true } }));
    const { wrapper } = await open('/owner/tenants/t-1');

    await wrapper.get('[data-action="suspend"]').trigger('click');
    expect(wrapper.get('[data-form="suspend"]').text()).toContain('Подписка и списания не отменяются');
    await wrapper.get('[data-form="suspend"]').trigger('submit');
    await flushPromises();
    await wrapper.get('[data-form="suspend"]').trigger('submit');
    await flushPromises();

    expect(mocked.previewTenantAction).toHaveBeenCalledWith('t-1', 'suspend', {});
    expect(mocked.applyTenantAction).not.toHaveBeenCalled();
    expect(wrapper.get('[data-preview="action"]').text()).toContain('Пауза: вручную');
    expect(wrapper.get('[data-action="confirm"]').attributes('disabled')).toBeDefined();

    await wrapper.get('[data-field="reason"]').setValue('Abuse report');
    await wrapper.get('[data-field="step-up"]').setValue('123456');
    await wrapper.get('[data-form="suspend"]').trigger('submit');
    await flushPromises();

    expect(mocked.applyTenantAction).toHaveBeenCalledWith('t-1', 'suspend', {}, { reason: 'Abuse report', confirmation_code: '123456' });
    expect(wrapper.get('[data-done="suspend"]').text()).toContain('Сервис приостановлен');
    expect(wrapper.find('[data-badge="suspended"]').exists()).toBe(true);
    expect(wrapper.find('[data-action="resume"]').exists()).toBe(true);
  });

  it('reminds the customer about a payment after showing the email, and writes an own message', async () => {
    mocked.previewTenantReminder.mockResolvedValue({
      recipients: [{ user_id: 'u-1', name: 'Anna', email: 'anna@example.test', locale: 'ru' }],
      letters: [{ locale: 'ru', subject: 'Business Watchdog: напоминание об оплате «Kaffee Lindner GmbH»', body: 'Здравствуйте!\n\nНапоминаем об оплате.' }],
      last_sent_at: '2026-10-19T10:00:00Z',
    });
    mocked.sendTenantReminder.mockResolvedValue({ topic: 'payment', recipients: 1 });
    const { wrapper } = await open('/owner/tenants/t-1');

    await wrapper.get('[data-action="remind"]').trigger('click');
    await wrapper.get('[data-topic="payment"]').setValue(true);
    expect(wrapper.find('[data-action="reminder-send"]').exists()).toBe(false);
    await wrapper.get('[data-form="reminder"]').trigger('submit');
    await flushPromises();

    expect(mocked.previewTenantReminder).toHaveBeenCalledWith('t-1', { topic: 'payment' });
    expect(wrapper.get('[data-recipients]').text()).toBe('Anna <anna@example.test>');
    expect(wrapper.get('[data-letter="ru"]').text()).toContain('напоминание об оплате');
    expect(wrapper.find('[data-last-sent]').exists()).toBe(true);
    expect(mocked.sendTenantReminder).not.toHaveBeenCalled();

    await wrapper.get('[data-form="reminder"]').trigger('submit');
    await flushPromises();
    expect(mocked.sendTenantReminder).toHaveBeenCalledWith('t-1', { topic: 'payment' });
    expect(mocked.fetchTenant).toHaveBeenCalledTimes(2);
    expect(wrapper.get('[data-done="reminder"]').text()).toContain('получателей: 1');
    expect(wrapper.find('[data-form="reminder"]').exists()).toBe(false);

    await wrapper.get('[data-action="remind"]').trigger('click');
    await wrapper.get('[data-topic="other"]').setValue(true);
    expect(wrapper.get('[data-action="reminder-preview"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="reminder-subject"]').setValue('Новый отчёт');
    await wrapper.get('[data-field="reminder-message"]').setValue('Мы добавили отчёт по возвратам.');
    await wrapper.get('[data-form="reminder"]').trigger('submit');
    await flushPromises();
    expect(mocked.previewTenantReminder).toHaveBeenLastCalledWith('t-1', { topic: 'other', subject: 'Новый отчёт', message: 'Мы добавили отчёт по возвратам.' });
  });

  it('invites a team member for the customer and revokes a pending invitation', async () => {
    const pending = { id: 'inv-1', email: 'jonas@kaffee.test', role: 'operator' as const, expires_at: '2026-10-27T10:00:00Z', created_at: '2026-10-20T10:00:00Z', revoked_at: null };
    mocked.fetchTenantInvitations.mockResolvedValueOnce([]).mockResolvedValueOnce([pending]).mockResolvedValueOnce([]);
    mocked.inviteTenantMember.mockResolvedValue(pending);
    mocked.revokeTenantInvitation.mockResolvedValue({ ...pending, revoked_at: '2026-10-20T11:00:00Z' });
    const { wrapper } = await open('/owner/tenants/t-1?tab=members');

    expect(wrapper.get('[data-member="anna@example.test"]').text()).toContain('Владелец');
    await wrapper.get('[data-action="invite"]').trigger('click');
    await wrapper.get('[data-field="invite-email"]').setValue('jonas@kaffee.test');
    expect(wrapper.get('[data-action="send-invite"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="invite-reason"]').setValue('Kunde hat angerufen');
    await wrapper.get('[data-form="invite"]').trigger('submit');
    await flushPromises();

    expect(mocked.inviteTenantMember).toHaveBeenCalledWith('t-1', { email: 'jonas@kaffee.test', role: 'operator', reason: 'Kunde hat angerufen' });
    expect(wrapper.get('[data-done="team"]').text()).toBe('Приглашение отправлено.');
    expect(wrapper.get('[data-invitation="jonas@kaffee.test"]').text()).toContain('Оператор');

    await wrapper.get('[data-action="revoke"]').trigger('click');
    await wrapper.get('[data-field="revoke-reason"]').setValue('Falsche Adresse');
    await wrapper.get('[data-form="revoke"]').trigger('submit');
    await flushPromises();
    expect(mocked.revokeTenantInvitation).toHaveBeenCalledWith('t-1', 'inv-1', 'Falsche Adresse');
    expect(wrapper.find('[data-invitation="jonas@kaffee.test"]').exists()).toBe(false);
  });

  it('adds a store for the customer with a reason', async () => {
    mocked.createCustomerStore.mockResolvedValue({ id: 's-2', name: 'Zweiter Shop', base_url: 'https://zweiter.example', platform: 'shopware', status: 'onboarding', default_currency: 'EUR', timezone: 'Europe/Berlin' });
    const { wrapper } = await open('/owner/tenants/t-1?tab=stores');

    expect(wrapper.get('[data-open-store]').attributes('href')).toBe('/owner/tenants/t-1/stores/s-1');
    await wrapper.get('[data-action="add-store"]').trigger('click');
    expect(wrapper.get('[data-action="confirm-store"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="store-name"]').setValue('Zweiter Shop');
    await wrapper.get('[data-field="store-url"]').setValue('https://zweiter.example');
    await wrapper.get('[data-field="store-platform"]').setValue('shopware');
    await wrapper.get('[data-field="reason"]').setValue('Kunde hat angerufen');
    await wrapper.get('[data-form="add-store"]').trigger('submit');
    await flushPromises();

    expect(mocked.createCustomerStore).toHaveBeenCalledWith('t-1', { reason: 'Kunde hat angerufen', name: 'Zweiter Shop', base_url: 'https://zweiter.example', timezone: 'Europe/Berlin', default_currency: 'EUR', locale: 'de', platform: 'shopware' });
    expect(wrapper.get('[data-done="store"]').text()).toBe('Магазин добавлен.');
    expect(mocked.fetchTenant).toHaveBeenCalledTimes(2);
  });

  it('shows findings, orders with details, checks with screenshots and incidents of a customer store', async () => {
    mocked.fetchCustomerStore.mockResolvedValue({ id: 's-1', name: 'Shop', base_url: 'https://kaffee.example', platform: 'woocommerce', status: 'active', default_currency: 'EUR', timezone: 'Europe/Berlin' });
    mocked.fetchStoreFindings.mockResolvedValue({ data: [{ id: 'f-1', order_id: 'o-1', order_display_number: '#15238', rule_code: 'MONEY_CAPTURE_MISSING', status: 'mismatch', reason_code: 'x', currency: 'EUR', currency_exponent: 2, difference_minor: '18400', evaluated_at: '2026-10-20T10:00:00Z' }], next_cursor: 'c-2' });
    mocked.fetchStoreOrders.mockResolvedValue({ data: [{ id: 'o-1', display_number: '#15238', status: 'processing', gateway: 'stripe', mode: 'live', currency: 'EUR', currency_exponent: 2, total_minor: '18400', paid_marked_at: null, is_synthetic: false, source_created_at: '2026-10-20T07:00:00Z' }], next_cursor: null });
    mocked.fetchCustomerOrder.mockResolvedValue({ id: 'o-1', display_number: '#15238', status: 'processing', gateway: 'stripe', mode: 'live', currency: 'EUR', currency_exponent: 2, total_minor: '18400', paid_marked_at: null, is_synthetic: false, source_created_at: null, transaction_ref: null, financial_support: 'supported', findings: [], captures: [{ id: 'tx-1', kind: 'capture', status: 'succeeded', currency: 'EUR', currency_exponent: 2, amount_minor: '18400', occurred_at: null }], refunds: [], revisions: [{}, {}] });
    mocked.fetchStoreChecks.mockResolvedValue({ data: [{ id: 'r-1', trigger: 'scheduled', status: 'failed', error_code: 'site_failure', scheduled_at: '2026-10-20T09:00:00Z', started_at: null, finished_at: '2026-10-20T09:01:00Z' }], next_cursor: null });
    mocked.fetchCustomerCheck.mockResolvedValue({ id: 'r-1', trigger: 'scheduled', status: 'failed', error_code: 'site_failure', scheduled_at: '2026-10-20T09:00:00Z', started_at: null, finished_at: '2026-10-20T09:01:00Z', attempts: [{ id: 'a-1', attempt_number: 1, status: 'failed', error_code: 'site_failure', started_at: '2026-10-20T09:00:00Z', finished_at: null, artifacts: [{ id: 'art-1', kind: 'screenshot', content_type: 'image/jpeg', size_bytes: 2048, expires_at: '2026-11-19T00:00:00Z' }], steps: [{ index: 0, code: 'payment_form', status: 'failed', error_code: 'selector_changed' }] }] });
    mocked.fetchCustomerArtifactUrl.mockResolvedValue({ url: 'https://objects.example.test/shot.jpg', content_type: 'image/jpeg' });
    mocked.fetchStoreIncidents.mockResolvedValue({ data: [{ id: 'i-1', family: 'money', state: 'open', severity: 'warning', title_code: 'MONEY_CAPTURE_MISSING', currency: 'EUR', currency_exponent: 2, verified_discrepancy_minor: '18400', first_seen_at: null, last_seen_at: '2026-10-20T10:00:00Z', resolved_at: null }], next_cursor: null });
    const { wrapper, router } = await open('/owner/tenants/t-1/stores/s-1');

    expect(mocked.fetchCustomerStore).toHaveBeenCalledWith('t-1', 's-1');
    expect(wrapper.get('h1').text()).toBe('Shop');
    expect(wrapper.get('[data-finding="f-1"]').text()).toContain('#15238');
    expect(wrapper.get('[data-finding="f-1"]').text()).toMatch(/184,00/);
    mocked.fetchStoreFindings.mockResolvedValueOnce({ data: [], next_cursor: null });
    await wrapper.get('[data-action="load-more"]').trigger('click');
    await flushPromises();
    expect(mocked.fetchStoreFindings).toHaveBeenLastCalledWith('t-1', 's-1', 'c-2');

    await router.replace({ query: { tab: 'orders' } });
    await flushPromises();
    await wrapper.get('[data-order="o-1"] button').trigger('click');
    await flushPromises();
    expect(mocked.fetchCustomerOrder).toHaveBeenCalledWith('t-1', 'o-1');
    expect(wrapper.get('[data-detail="order"]').text()).toContain('Заказ #15238');
    expect(wrapper.get('[data-detail="order"]').text()).toContain('succeeded');

    await router.replace({ query: { tab: 'checks' } });
    await flushPromises();
    await wrapper.get('[data-check="r-1"] button').trigger('click');
    await flushPromises();
    expect(wrapper.get('[data-attempt="1"]').text()).toContain('payment_form');
    await wrapper.get('[data-action="show-screenshot"]').trigger('click');
    await flushPromises();
    expect(mocked.fetchCustomerArtifactUrl).toHaveBeenCalledWith('t-1', 'art-1');
    expect(wrapper.get('[data-artifact="art-1"] img').attributes('src')).toBe('https://objects.example.test/shot.jpg');

    await router.replace({ query: { tab: 'incidents' } });
    await flushPromises();
    await wrapper.get('[data-incident="i-1"] button').trigger('click');
    expect(wrapper.get('[data-detail="incident"]').text()).toMatch(/184,00/);
  });

  it('grants temporary access of a published plan until a date', async () => {
    mocked.previewTenantAction.mockResolvedValue({ action: 'grant_override', access_before: { state: 'active', reasons: [] }, access_after: { state: 'override', reasons: [] }, stores: 1, effective_at: '2026-11-20T10:00:00Z' });
    const { wrapper } = await open('/owner/tenants/t-1');

    await wrapper.get('[data-action="grant_override"]').trigger('click');
    expect(wrapper.get('[data-action="preview"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="extra-stores"]').setValue(2);
    await wrapper.get('[data-field="until"]').setValue('2026-11-20T10:00');
    await wrapper.get('[data-form="grant_override"]').trigger('submit');
    await flushPromises();

    expect(mocked.previewTenantAction).toHaveBeenCalledWith('t-1', 'grant_override', { plan_version_id: 'v-1', extra_stores: 2, ends_at: new Date('2026-11-20T10:00').toISOString() });
    expect(wrapper.get('[data-preview="action"]').text()).toContain('Временный доступ');
  });

  it('shows the subscription tab with provider contracts and no actions for read-only staff', async () => {
    const { wrapper } = await open('/owner/tenants/t-1?tab=subscription', ['tenants.read', 'billing.read']);

    expect(wrapper.get('[data-subscription]').text()).toContain('sub_1');
    expect(wrapper.get('[data-contract="sub_1"]').text()).toContain('Активен');

    await wrapper.get('[data-tab="overview"]').trigger('click');
    await flushPromises();
    expect(wrapper.find('[data-panel="actions"]').exists()).toBe(false);
  });

  it('shows the orders of every store this month against the package limit', async () => {
    const { wrapper } = await open('/owner/tenants/t-1?tab=stores');

    const orders = wrapper.get('[data-store="kaffee.example"] [data-orders]').text();
    expect(orders).toContain('640');
    expect(orders).toContain('в среднем 1267 / лимит 1000');
    expect(orders).toContain('Сверх пакета с');
  });

  it('hides the subscription tab when billing is not visible to the role', async () => {
    mocked.fetchTenant.mockResolvedValue(card({ billing: null }));
    const { wrapper } = await open('/owner/tenants/t-1', ['tenants.read']);

    expect(wrapper.find('[data-tab="subscription"]').exists()).toBe(false);
    expect(wrapper.find('[data-tab="stores"]').exists()).toBe(true);
  });

  it('lists subscriptions with their organisation and next charge', async () => {
    mocked.fetchSubscriptions.mockResolvedValue({ data: [{ ...ROW.subscription!, tenant: { id: 't-1', name: 'Kaffee Lindner GmbH', status: 'active' }, access: { state: 'active', reasons: [] }, next_charge_at: null, provider_subscription_ref: 'sub_1', mode: 'test' }], next_cursor: null });
    const { wrapper } = await open('/owner/subscriptions');

    const row = wrapper.get('[data-subscription="Kaffee Lindner GmbH"]');
    expect(row.text()).toContain('sub_1 · test');
    expect(row.text()).toContain('start + 1');
  });
});
