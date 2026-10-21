import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api';
import type { StaffMe } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';

vi.mock('@/api', () => ({
  fetchMe: vi.fn(),
  login: vi.fn(),
  submitMfa: vi.fn(),
  logout: vi.fn(),
  fetchPayments: vi.fn(),
  fetchRefunds: vi.fn(),
  requestRefund: vi.fn(),
  approveRefund: vi.fn(),
  rejectRefund: vi.fn(),
}));

const mocked = vi.mocked(api);

function staff(capabilities: string[] = ['billing.read']): StaffMe {
  return { staff_id: 's-1', name: 'Founder', email: 'f@example.test', locale: 'ru', role: 'platform_owner', mfa_enabled: true, capabilities, billing_mode: 'test', session: { idle_minutes: 30, absolute_hours: 8 } };
}

beforeEach(() => {
  vi.clearAllMocks();
  useStaff().forget();
  localization.setLocale('ru');
  mocked.fetchRefunds.mockResolvedValue([]);
});

describe('payments', () => {
  it('shows the month per currency with tax, Paddle fee and what is left, and filters payments by status', async () => {
    mocked.fetchMe.mockResolvedValue(staff());
    mocked.fetchPayments.mockResolvedValue({
      data: [{ id: 'p-1', provider_transaction_ref: 'txn_1', status: 'completed', origin: 'web', invoice_number: 'INV-1', currency: 'EUR', currency_exponent: 2, subtotal_minor: '2300', discount_minor: '400', tax_minor: '437', total_minor: '2737', fee_minor: '186', earnings_minor: '2551', period_start: null, period_end: null, billed_at: '2026-10-11T12:00:00Z', tenant: { id: 't-1', name: 'Kaffee GmbH' } }],
      next_cursor: null,
      month: [
        { currency: 'EUR', currency_exponent: 2, payments: 2, total_minor: '5474', tax_minor: '874', fee_minor: '372', earnings_minor: '5102' },
        { currency: 'USD', currency_exponent: 2, payments: 1, total_minor: '1900', tax_minor: '0', fee_minor: null, earnings_minor: null },
      ],
      mode: 'test',
    });
    await useStaff().load(true);
    const router = buildRouter();
    await router.push('/owner/payments');
    await router.isReady();
    const wrapper = mount(App, { global: { plugins: [localization, router] } });
    await flushPromises();
    await vi.waitFor(() => expect(wrapper.find('[data-month="EUR"]').exists()).toBe(true));

    const eur = wrapper.get('[data-month="EUR"]').text();
    expect(eur).toContain('54,74');
    expect(eur).toContain('8,74');
    expect(eur).toContain('3,72');
    expect(eur).toContain('51,02');
    expect(wrapper.get('[data-month="USD"]').text()).toContain('ещё не известно');
    expect(wrapper.text()).toContain('тестовый режим Paddle');
    const row = wrapper.get('[data-payment="txn_1"]').text();
    expect(row).toContain('Kaffee GmbH');
    expect(row).toContain('27,37');
    expect(row).toContain('Оплачен');

    await wrapper.get('[data-field="status"]').setValue('past_due');
    await wrapper.get('[data-form="payment-filters"]').trigger('submit');
    await flushPromises();
    expect(mocked.fetchPayments).toHaveBeenLastCalledWith({ status: 'past_due' });
    expect(router.currentRoute.value.query).toEqual({ status: 'past_due' });
  });

  it('lets the owner send a partial refund with a code without changing the plan', async () => {
    mocked.fetchMe.mockResolvedValue(staff(['billing.read', 'refunds.request', 'refunds.approve']));
    mocked.fetchPayments.mockResolvedValue({
      data: [{ id: 'p-1', provider_transaction_ref: 'txn_1', status: 'completed', origin: 'web', invoice_number: 'INV-1', currency: 'EUR', currency_exponent: 2, subtotal_minor: '2300', discount_minor: '400', tax_minor: '437', total_minor: '2737', fee_minor: '186', earnings_minor: '2551', refunded_minor: '500', period_start: null, period_end: null, billed_at: '2026-10-11T12:00:00Z', tenant: { id: 't-1', name: 'Kaffee GmbH' } }],
      next_cursor: null,
      month: [],
      mode: 'test',
    });
    const pending: api.Refund = { id: 'r-1', tenant: { id: 't-1', name: 'Kaffee GmbH' }, transaction_id: 'p-1', provider_transaction_ref: 'txn_1', invoice_number: 'INV-1', kind: 'partial', amount_minor: '1000', tax_minor: null, currency: 'EUR', currency_exponent: 2, reason: 'Ошибка тарифа', cancel_subscription: false, status: 'requested', provider_status: null, error_code: null, requested_by: 'Anna', decided_by: null, decision_reason: null, created_at: '2026-10-13T10:00:00Z', decided_at: null };
    mocked.fetchRefunds.mockResolvedValue([pending]);
    mocked.requestRefund.mockResolvedValue({ id: 'r-2' });
    mocked.approveRefund.mockResolvedValue({ id: 'r-1', status: 'pending' });
    await useStaff().load(true);
    const router = buildRouter();
    await router.push('/owner/payments');
    await router.isReady();
    const wrapper = mount(App, { global: { plugins: [localization, router] } });
    await flushPromises();
    await vi.waitFor(() => expect(wrapper.find('[data-payment="txn_1"]').exists()).toBe(true));

    expect(wrapper.get('[data-payment="txn_1"]').text()).toContain('возвращено 5,00');
    await wrapper.get('[data-payment="txn_1"] [data-action="partial-refund"]').trigger('click');
    expect((wrapper.get('[data-field="refund-amount"]').element as HTMLInputElement).value).toBe('');
    await wrapper.get('[data-field="refund-amount"]').setValue('10,5');
    await wrapper.get('[data-field="refund-reason"]').setValue('Ошибка тарифа');
    await wrapper.get('[data-field="refund-code"]').setValue('123456');
    await wrapper.get('[data-form="refund"]').trigger('submit');
    await flushPromises();
    expect(mocked.requestRefund).toHaveBeenCalledWith('p-1', { amount_minor: '1050', reason: 'Ошибка тарифа', cancel_subscription: false });
    expect(mocked.approveRefund).toHaveBeenCalledWith('r-2', { reason: 'Ошибка тарифа', confirmation_code: '123456' });
    expect(wrapper.find('[data-refund="requested"]').exists()).toBe(true);

    const item = wrapper.get('[data-refund="r-1"]');
    expect(item.text()).toContain('Ждёт подтверждения');
    expect(item.text()).not.toContain('отмена подписки');
  });

  it('opens a full refund from the tenant and locks the payment amount', async () => {
    mocked.fetchMe.mockResolvedValue(staff(['billing.read', 'refunds.request', 'refunds.approve']));
    mocked.fetchPayments.mockResolvedValue({
      data: [{ id: 'p-1', provider_transaction_ref: 'txn_1', status: 'completed', origin: 'subscription_recurring', invoice_number: 'INV-1', currency: 'EUR', currency_exponent: 2, subtotal_minor: '1900', discount_minor: '0', tax_minor: '0', total_minor: '1900', fee_minor: '0', earnings_minor: '1900', refunded_minor: '0', period_start: null, period_end: null, billed_at: '2026-10-11T12:00:00Z', tenant: { id: 't-1', name: 'Kaffee GmbH' } }],
      next_cursor: null, month: [], mode: 'test',
    });
    mocked.requestRefund.mockResolvedValue({ id: 'r-1' });
    mocked.approveRefund.mockResolvedValue({ id: 'r-1', status: 'pending' });
    await useStaff().load(true);
    const router = buildRouter();
    await router.push('/owner/payments?tenant_id=t-1');
    await router.isReady();
    const wrapper = mount(App, { global: { plugins: [localization, router] } });
    await flushPromises();
    expect(mocked.fetchPayments).toHaveBeenCalledWith({ tenant_id: 't-1' });
    await wrapper.get('[data-action="cancel-and-refund"]').trigger('click');
    expect((wrapper.get('[data-field="refund-amount"]').element as HTMLInputElement).readOnly).toBe(true);
    expect((wrapper.get('[data-field="refund-amount"]').element as HTMLInputElement).value).toBe('19.00');
    await wrapper.get('[data-field="refund-reason"]').setValue('Customer request');
    await wrapper.get('[data-field="refund-code"]').setValue('123456');
    await wrapper.get('[data-form="refund"]').trigger('submit');
    await flushPromises();
    expect(mocked.requestRefund).toHaveBeenCalledWith('p-1', { amount_minor: '1900', reason: 'Customer request', cancel_subscription: true });
    expect(mocked.approveRefund).toHaveBeenCalledWith('r-1', { reason: 'Customer request', confirmation_code: '123456' });
  });

  it('hides refund actions from staff without refund rights', async () => {
    mocked.fetchMe.mockResolvedValue(staff());
    mocked.fetchPayments.mockResolvedValue({
      data: [{ id: 'p-1', provider_transaction_ref: 'txn_1', status: 'completed', origin: 'web', invoice_number: 'INV-1', currency: 'EUR', currency_exponent: 2, subtotal_minor: '2300', discount_minor: '400', tax_minor: '437', total_minor: '2737', fee_minor: '186', earnings_minor: '2551', period_start: null, period_end: null, billed_at: '2026-10-11T12:00:00Z', tenant: { id: 't-1', name: 'Kaffee GmbH' } }],
      next_cursor: null,
      month: [],
      mode: 'test',
    });
    mocked.fetchRefunds.mockResolvedValue([{ id: 'r-1', tenant: { id: 't-1', name: 'Kaffee GmbH' }, transaction_id: 'p-1', provider_transaction_ref: 'txn_1', invoice_number: 'INV-1', kind: 'partial', amount_minor: '1000', tax_minor: null, currency: 'EUR', currency_exponent: 2, reason: 'Ошибка', cancel_subscription: false, status: 'requested', provider_status: null, error_code: null, requested_by: 'Anna', decided_by: null, decision_reason: null, created_at: '2026-10-13T10:00:00Z', decided_at: null }]);
    await useStaff().load(true);
    const router = buildRouter();
    await router.push('/owner/payments');
    await router.isReady();
    const wrapper = mount(App, { global: { plugins: [localization, router] } });
    await flushPromises();
    await vi.waitFor(() => expect(wrapper.find('[data-refund="r-1"]').exists()).toBe(true));

    expect(wrapper.find('[data-action="partial-refund"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="cancel-and-refund"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="approve-refund"]').exists()).toBe(false);
  });
});
