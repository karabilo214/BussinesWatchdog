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
  fetchTenantReminders: vi.fn(),
  saveReminderTemplate: vi.fn(),
  createCustomerStore: vi.fn(),
  fetchCustomerStore: vi.fn(),
  fetchStoreFindings: vi.fn(),
  fetchStoreOrders: vi.fn(),
  fetchStoreChecks: vi.fn(),
  fetchStoreIncidents: vi.fn(),
  rerunStoreCheck: vi.fn(),
  changeTenantMemberRole: vi.fn(),
  removeTenantMember: vi.fn(),
  changeStoreIncident: vi.fn(),
  fetchCustomerOrder: vi.fn(),
  fetchCustomerCheck: vi.fn(),
  fetchCustomerArtifactUrl: vi.fn(),
  fetchTenantInvitations: vi.fn(),
  inviteTenantMember: vi.fn(),
  revokeTenantInvitation: vi.fn(),
  resetCustomerMfa: vi.fn(),
  fetchSubscriptions: vi.fn(),
  requestTenantDeletion: vi.fn(),
  cancelTenantDeletion: vi.fn(),
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
    deletion: null,
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
  it('deletes an organization only with tenants.delete, a reason and a code, and can cancel it', async () => {
    const scheduled = card({ status: 'deleting', deletion: { status: 'requested', requested_via: 'platform', requested_at: '2026-10-20T10:00:00Z', purge_after: '2026-11-19T10:00:00Z', can_cancel: true } });
    mocked.requestTenantDeletion.mockResolvedValue(scheduled);
    mocked.cancelTenantDeletion.mockResolvedValue(card());

    const { wrapper: support } = await open('/owner/tenants/t-1');
    expect(support.find('[data-action="open-delete-tenant"]').exists()).toBe(false);
    support.unmount();
    useStaff().forget();

    const { wrapper } = await open('/owner/tenants/t-1', ['tenants.read', 'tenants.manage', 'tenants.delete']);
    await wrapper.get('[data-action="open-delete-tenant"]').trigger('click');
    expect(wrapper.get('[data-form="tenant-deletion"]').text()).toContain('Kaffee Lindner GmbH');
    expect(wrapper.get('[data-action="confirm-tenant-deletion"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-form="tenant-deletion"] [data-field="reason"]').setValue('Customer asked by email');
    await wrapper.get('[data-form="tenant-deletion"] [data-field="step-up"]').setValue('123456');
    await wrapper.get('[data-form="tenant-deletion"]').trigger('submit');
    await flushPromises();

    expect(mocked.requestTenantDeletion).toHaveBeenCalledWith('t-1', { reason: 'Customer asked by email', confirmation_code: '123456' });
    expect(wrapper.get('[data-panel="tenant-deletion"]').text()).toContain('будет удалена');
    expect(wrapper.find('[data-badge="deleting"]').exists()).toBe(true);

    await wrapper.get('[data-action="open-cancel-deletion"]').trigger('click');
    await wrapper.get('[data-form="tenant-deletion"] [data-field="reason"]').setValue('Customer changed their mind');
    await wrapper.get('[data-form="tenant-deletion"] [data-field="step-up"]').setValue('654321');
    await wrapper.get('[data-form="tenant-deletion"]').trigger('submit');
    await flushPromises();

    expect(mocked.cancelTenantDeletion).toHaveBeenCalledWith('t-1', { reason: 'Customer changed their mind', confirmation_code: '654321' });
    expect(wrapper.find('[data-panel="tenant-deletion"]').exists()).toBe(false);
  });

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

  it('reminds chosen members with an edited letter, saves the template and shows the sent letters', async () => {
    const template = { subject: 'Business Watchdog: напоминание об оплате «:tenant»', body: 'Здравствуйте!\n\nНапоминаем об оплате: :link', customized: false };
    mocked.previewTenantReminder.mockResolvedValue({
      members: [
        { user_id: 'u-1', name: 'Anna', email: 'anna@example.test', locale: 'ru', role: 'owner', active: true },
        { user_id: 'u-2', name: 'Jonas', email: 'jonas@example.test', locale: 'de', role: 'operator', active: true },
        { user_id: 'u-3', name: 'Gone', email: 'gone@example.test', locale: 'ru', role: 'owner', active: false },
      ],
      suggested_recipient_ids: ['u-1'],
      templates: { ru: template, en: { ...template, subject: 'Payment reminder for :tenant' }, de: { ...template, subject: 'Zahlungserinnerung für :tenant', body: 'Hallo,\n\nbitte prüfen: :link' } },
      placeholders: [':tenant', ':link'],
      letters: [{ locale: 'ru', subject: 'Business Watchdog: напоминание об оплате «Kaffee Lindner GmbH»', body: 'Здравствуйте!' }],
      last_sent_at: '2026-10-19T10:00:00Z',
    });
    mocked.saveReminderTemplate.mockResolvedValue({ subject: 'Оплата «:tenant»', body: 'Здравствуйте!\n\nНапоминаем об оплате: :link', customized: true });
    mocked.sendTenantReminder.mockResolvedValue({ id: 'r-1', topic: 'payment', sent_at: '2026-10-20T10:00:00Z', sender: { name: 'Founder', email: 'f@example.test' }, messages: [{ email: 'anna@example.test', locale: 'ru', subject: 'Оплата', body: 'x' }, { email: 'jonas@example.test', locale: 'de', subject: 'Zahlung', body: 'y' }] });
    const { wrapper } = await open('/owner/tenants/t-1');

    await wrapper.get('[data-action="remind"]').trigger('click');
    await wrapper.get('[data-topic="payment"]').setValue(true);
    await flushPromises();
    expect(mocked.previewTenantReminder).toHaveBeenCalledWith('t-1', { topic: 'payment' });
    expect((wrapper.get('[data-recipient="anna@example.test"]').element as HTMLInputElement).checked).toBe(true);
    expect(wrapper.get('[data-recipient="gone@example.test"]').attributes('disabled')).toBeDefined();
    expect(wrapper.find('[data-last-sent]').exists()).toBe(true);
    expect(wrapper.find('[data-letter-editor="de"]').exists()).toBe(false);

    await wrapper.get('[data-recipient="jonas@example.test"]').setValue(true);
    expect((wrapper.get('[data-field="subject-de"]').element as HTMLInputElement).value).toBe('Zahlungserinnerung für :tenant');
    await wrapper.get('[data-field="subject-ru"]').setValue('Оплата «:tenant»');
    await wrapper.get('[data-action="save-template-ru"]').trigger('click');
    await flushPromises();
    expect(mocked.saveReminderTemplate).toHaveBeenCalledWith('payment', 'ru', { subject: 'Оплата «:tenant»', body: 'Здравствуйте!\n\nНапоминаем об оплате: :link' });
    expect(wrapper.find('[data-template-saved]').exists()).toBe(true);

    await wrapper.get('[data-action="reminder-preview"]').trigger('click');
    await flushPromises();
    expect(mocked.previewTenantReminder).toHaveBeenLastCalledWith('t-1', { topic: 'payment', recipient_ids: ['u-1', 'u-2'], letters: { ru: { subject: 'Оплата «:tenant»', body: 'Здравствуйте!\n\nНапоминаем об оплате: :link' }, de: { subject: 'Zahlungserinnerung für :tenant', body: 'Hallo,\n\nbitte prüfen: :link' } } });
    expect(wrapper.get('[data-letter="ru"]').text()).toContain('Kaffee Lindner GmbH');

    await wrapper.get('[data-form="reminder"]').trigger('submit');
    await flushPromises();
    expect(mocked.sendTenantReminder).toHaveBeenCalledWith('t-1', { topic: 'payment', recipient_ids: ['u-1', 'u-2'], letters: { ru: { subject: 'Оплата «:tenant»', body: 'Здравствуйте!\n\nНапоминаем об оплате: :link' }, de: { subject: 'Zahlungserinnerung für :tenant', body: 'Hallo,\n\nbitte prüfen: :link' } } });
    expect(wrapper.get('[data-done="reminder"]').text()).toContain('получателей: 2');
    expect(wrapper.find('[data-form="reminder"]').exists()).toBe(false);
  });

  it('shows every sent reminder with its letters in the card', async () => {
    mocked.fetchTenantReminders.mockResolvedValue([{ id: 'r-1', topic: 'other', sent_at: '2026-10-20T10:00:00Z', sender: { name: 'Founder', email: 'f@example.test' }, messages: [{ email: 'anna@example.test', locale: 'ru', subject: 'Новый отчёт', body: 'Мы добавили отчёт по возвратам.' }] }]);
    const { wrapper } = await open('/owner/tenants/t-1?tab=reminders');

    expect(mocked.fetchTenantReminders).toHaveBeenCalledWith('t-1');
    const item = wrapper.get('[data-reminder="r-1"]');
    expect(item.text()).toContain('Другое');
    expect(item.text()).toContain('Founder');
    expect(item.get('[data-message="anna@example.test"]').text()).toContain('отчёт по возвратам');
  });

  it('changes the role of a customer member and removes one, never offering it for the owner', async () => {
    const members = [
      { user_id: 'u-1', role: 'owner', mfa_enabled: true, joined_at: null, name: 'Anna', email: 'anna@example.test', email_verified: true },
      { user_id: 'u-2', role: 'operator', mfa_enabled: false, joined_at: null, name: 'Jonas', email: 'jonas@example.test', email_verified: true },
    ];
    mocked.fetchTenant.mockResolvedValue(card({ members }));
    mocked.fetchTenantInvitations.mockResolvedValue([]);
    mocked.changeTenantMemberRole.mockResolvedValue({ user_id: 'u-2', role: 'admin' });
    mocked.removeTenantMember.mockResolvedValue();
    const { wrapper } = await open('/owner/tenants/t-1?tab=members');

    expect(wrapper.get('[data-member="anna@example.test"]').find('[data-action="change-role"]').exists()).toBe(false);
    await wrapper.get('[data-member="jonas@example.test"] [data-action="change-role"]').trigger('click');
    await wrapper.get('[data-field="member-role"]').setValue('admin');
    expect(wrapper.get('[data-action="member-confirm"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="member-reason"]').setValue('Customer asked');
    await wrapper.get('[data-form="member-role"]').trigger('submit');
    await flushPromises();
    expect(mocked.changeTenantMemberRole).toHaveBeenCalledWith('t-1', 'u-2', 'admin', 'Customer asked');
    expect(wrapper.get('[data-done="team"]').text()).toBe('Роль изменена.');

    await wrapper.get('[data-member="jonas@example.test"] [data-action="remove-member"]').trigger('click');
    await wrapper.get('[data-field="member-reason"]').setValue('Left the company');
    await wrapper.get('[data-form="member-remove"]').trigger('submit');
    await flushPromises();
    expect(mocked.removeTenantMember).toHaveBeenCalledWith('t-1', 'u-2', 'Left the company');
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
    mocked.fetchStoreChecks.mockResolvedValue({ data: [{ id: 'r-1', scenario_id: 'sc-1', trigger: 'scheduled', status: 'failed', error_code: 'site_failure', scheduled_at: '2026-10-20T09:00:00Z', started_at: null, finished_at: '2026-10-20T09:01:00Z' }], next_cursor: null });
    mocked.fetchCustomerCheck.mockResolvedValue({ id: 'r-1', scenario_id: 'sc-1', trigger: 'scheduled', status: 'failed', error_code: 'site_failure', scheduled_at: '2026-10-20T09:00:00Z', started_at: null, finished_at: '2026-10-20T09:01:00Z', attempts: [{ id: 'a-1', attempt_number: 1, status: 'failed', error_code: 'site_failure', started_at: '2026-10-20T09:00:00Z', finished_at: null, artifacts: [{ id: 'art-1', kind: 'screenshot', content_type: 'image/jpeg', size_bytes: 2048, expires_at: '2026-11-19T00:00:00Z' }], steps: [{ index: 0, code: 'payment_form', status: 'failed', error_code: 'selector_changed' }] }] });
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

  it('lets support rerun a check and handle an incident for the customer with a reason', async () => {
    const incident = { id: 'i-1', family: 'money', state: 'open', severity: 'warning', title_code: 'MONEY_CAPTURE_MISSING', currency: 'EUR', currency_exponent: 2, verified_discrepancy_minor: '18400', first_seen_at: null, last_seen_at: '2026-10-20T10:00:00Z', resolved_at: null };
    mocked.fetchCustomerStore.mockResolvedValue({ id: 's-1', name: 'Shop', base_url: 'https://kaffee.example', platform: 'woocommerce', status: 'active', default_currency: 'EUR', timezone: 'Europe/Berlin' });
    mocked.fetchStoreFindings.mockResolvedValue({ data: [], next_cursor: null });
    mocked.fetchStoreChecks.mockResolvedValue({ data: [{ id: 'r-1', scenario_id: 'sc-1', trigger: 'scheduled', status: 'failed', error_code: null, scheduled_at: '2026-10-20T09:00:00Z', started_at: null, finished_at: '2026-10-20T09:01:00Z' }], next_cursor: null });
    mocked.fetchStoreIncidents.mockResolvedValue({ data: [incident], next_cursor: null });
    mocked.rerunStoreCheck.mockResolvedValue({ id: 'r-2', scenario_id: 'sc-1', trigger: 'support', status: 'queued', error_code: null, scheduled_at: '2026-10-20T10:00:00Z', started_at: null, finished_at: null });
    mocked.changeStoreIncident.mockResolvedValueOnce({ ...incident, state: 'acknowledged' }).mockResolvedValueOnce({ ...incident, state: 'resolved', resolved_at: '2026-10-20T10:05:00Z' });
    const { wrapper, router } = await open('/owner/tenants/t-1/stores/s-1?tab=checks', ['tenants.read', 'tenants.support']);

    await wrapper.get('[data-action="support-rerun"]').trigger('click');
    expect(wrapper.get('[data-action="support-confirm"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="support-reason"]').setValue('Customer asked');
    await wrapper.get('[data-form="support-rerun"]').trigger('submit');
    await flushPromises();
    expect(mocked.rerunStoreCheck).toHaveBeenCalledWith('t-1', 's-1', 'sc-1', 'Customer asked');
    expect(wrapper.get('[data-support-done]').text()).toBe('Проверка запущена.');

    await router.replace({ query: { tab: 'incidents' } });
    await flushPromises();
    await wrapper.get('[data-incident="i-1"] button').trigger('click');
    await wrapper.get('[data-action="support-acknowledge"]').trigger('click');
    await wrapper.get('[data-field="support-reason"]').setValue('Looking into it');
    await wrapper.get('[data-form="support-acknowledge"]').trigger('submit');
    await flushPromises();
    expect(mocked.changeStoreIncident).toHaveBeenCalledWith('t-1', 'i-1', 'acknowledge', 'Looking into it');
    expect(wrapper.find('[data-action="support-acknowledge"]').exists()).toBe(false);
    await wrapper.get('[data-action="support-resolve"]').trigger('click');
    await wrapper.get('[data-field="support-reason"]').setValue('Fixed together');
    await wrapper.get('[data-form="support-resolve"]').trigger('submit');
    await flushPromises();
    expect(mocked.changeStoreIncident).toHaveBeenLastCalledWith('t-1', 'i-1', 'resolve', 'Fixed together');
    expect(wrapper.find('[data-action="support-resolve"]').exists()).toBe(false);
  });

  it('offers no support actions without the permission', async () => {
    mocked.fetchCustomerStore.mockResolvedValue({ id: 's-1', name: 'Shop', base_url: 'https://kaffee.example', platform: 'woocommerce', status: 'active', default_currency: 'EUR', timezone: 'Europe/Berlin' });
    mocked.fetchStoreChecks.mockResolvedValue({ data: [{ id: 'r-1', scenario_id: 'sc-1', trigger: 'scheduled', status: 'failed', error_code: null, scheduled_at: '2026-10-20T09:00:00Z', started_at: null, finished_at: null }], next_cursor: null });
    const { wrapper } = await open('/owner/tenants/t-1/stores/s-1?tab=checks', ['tenants.read']);

    expect(wrapper.find('[data-action="support-rerun"]').exists()).toBe(false);
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
