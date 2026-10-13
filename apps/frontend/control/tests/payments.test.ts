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
}));

const mocked = vi.mocked(api);

function staff(): StaffMe {
  return { staff_id: 's-1', name: 'Founder', email: 'f@example.test', locale: 'ru', role: 'platform_owner', mfa_enabled: true, capabilities: ['billing.read'], billing_mode: 'test', session: { idle_minutes: 30, absolute_hours: 8 } };
}

beforeEach(() => {
  vi.clearAllMocks();
  useStaff().forget();
  localization.setLocale('ru');
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
});
