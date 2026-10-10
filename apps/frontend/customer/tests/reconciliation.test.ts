import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api/reconciliation';
import type { Finding, OrderDetail, UnmatchedPayment } from '@/api/types';
import FindingsTable from '@/components/reconciliation/FindingsTable.vue';
import { linkableRefundAmount } from '@/components/reconciliation/linking';
import UnmatchedPayments from '@/components/reconciliation/UnmatchedPayments.vue';
import { localization, setLocale } from '@/i18n';

vi.mock('@/api/reconciliation', () => ({
  listUnmatchedPayments: vi.fn(),
  allocatePayment: vi.fn(),
}));

const mocked = vi.mocked(api);
const global = { plugins: [localization], stubs: { RouterLink: RouterLinkStub } };

function finding(overrides: Partial<Finding> = {}): Finding {
  return {
    id: 'f-1',
    run_id: 'r-1',
    order_id: 'o-1',
    payment_id: null,
    rule_code: 'MONEY_CAPTURE_AMOUNT',
    status: 'mismatch',
    reason_code: 'capture_amount_mismatch',
    currency: 'EUR',
    currency_exponent: 2,
    expected_minor: '18400',
    actual_minor: '18000',
    difference_minor: '400',
    evaluated_at: '2026-10-09T10:00:00Z',
    order_display_number: '#15238',
    ...overrides,
  };
}

function unmatched(): UnmatchedPayment {
  return {
    payment_id: 'p-1',
    capture_transaction_id: 'c-1',
    external_operation_id: 'ch_3Pdemo',
    currency: 'EUR',
    currency_exponent: 2,
    amount_minor: '7700',
    mode: 'live',
    occurred_at: '2026-10-09T09:00:00Z',
    reason: 'review_required',
    candidates: [
      { order_id: 'o-7', display_number: '#2002', confidence: 'manual_review', provider_ref: null, amount_minor: '7700', currency: 'EUR', currency_exponent: 2, mode: 'live' },
    ],
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
});

describe('FindingsTable', () => {
  it('colours only matches green, links the order and keeps unknown amounts unknown', () => {
    const wrapper = mount(FindingsTable, {
      props: { findings: [finding(), finding({ id: 'f-2', status: 'unknown', rule_code: 'MONEY_UNSUPPORTED', expected_minor: null, actual_minor: null, difference_minor: null })] },
      global,
    });
    const first = wrapper.findAll('[data-finding-id="f-1"] td').map((cell) => cell.text());
    const second = wrapper.findAll('[data-finding-id="f-2"] td').map((cell) => cell.text());

    expect(wrapper.get('[data-finding-id="f-1"] [data-tone]').attributes('data-tone')).toBe('crit');
    expect(wrapper.get('[data-finding-id="f-2"] [data-tone]').attributes('data-tone')).toBe('unknown');
    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'order', params: { id: 'o-1' } });
    expect(first[2]).toBe('#15238');
    expect(first[5]).toBe('4,00 €');
    expect(second[3]).toBe('неизвестно');
  });
});

describe('rule names', () => {
  it('every reconciliation rule has a neutral check name in all languages', async () => {
    const { RULE_CODES } = await import('@/components/reconciliation/tones');
    const locales = await Promise.all(['ru', 'en', 'de'].map((lang) => import(`@/i18n/locales/${lang}.json`)));

    for (const locale of locales) {
      for (const code of RULE_CODES) {
        expect(locale.default.reconciliation.rule[code], code).toBeTruthy();
      }
    }
  });

  it('labels an ok row with the check, not with the problem', () => {
    const wrapper = mount(FindingsTable, { props: { findings: [finding({ status: 'ok', difference_minor: '0' })] }, global });

    expect(wrapper.text()).toContain('Сумма списания');
    expect(wrapper.text()).not.toContain('не совпадает');
  });
});

describe('UnmatchedPayments', () => {
  it('matches the whole capture to the chosen order only with a reason', async () => {
    mocked.listUnmatchedPayments.mockResolvedValue({ data: [unmatched()], next_cursor: null });
    mocked.allocatePayment.mockResolvedValue({} as never);
    const wrapper = mount(UnmatchedPayments, { props: { storeId: 's-1', canAllocate: true }, global });
    await flushPromises();

    expect(wrapper.text()).toContain('77,00 €');
    expect(wrapper.text()).toContain('ch_3Pdemo');
    expect(wrapper.text()).toContain('совпадает только сумма');

    await wrapper.findAll('button').find((button) => button.text() === 'Сопоставить')!.trigger('click');
    const submit = wrapper.findAll('button').find((button) => button.text() === 'Сопоставить с заказом')!;
    expect(submit.attributes('disabled')).toBeDefined();

    await wrapper.get('textarea').setValue('Customer confirmed by email');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(mocked.allocatePayment).toHaveBeenCalledWith({
      order_id: 'o-7',
      payment_id: 'p-1',
      capture_transaction_id: 'c-1',
      amount_minor: '7700',
      currency: 'EUR',
      reason: 'Customer confirmed by email',
    });
    expect(wrapper.emitted('allocated')).toHaveLength(1);
  });

  it('does not offer matching to members who may not allocate', async () => {
    mocked.listUnmatchedPayments.mockResolvedValue({ data: [unmatched()], next_cursor: null });
    const wrapper = mount(UnmatchedPayments, { props: { storeId: 's-1', canAllocate: false }, global });
    await flushPromises();

    expect(wrapper.findAll('button').some((button) => button.text() === 'Сопоставить')).toBe(false);
  });
});

describe('linkableRefundAmount', () => {
  function order(): OrderDetail {
    return {
      id: 'o-1', store_id: 's-1', external_id: '15238', display_number: '#15238', status: 'refunded', gateway: 'stripe', mode: 'live',
      currency: 'EUR', currency_exponent: 2, total_minor: '18400', payment_expected: true, paid_marked_at: null, transaction_ref: null,
      financial_support: 'supported', is_synthetic: false, source_created_at: null, source_updated_at: null,
      findings: [], captures: [], revisions: [], allocations: [],
      refunds: [{ id: 'rf-1', order_id: 'o-1', external_id: '1', currency: 'EUR', currency_exponent: 2, amount_minor: '9223372036854775000', external_required: true, provider_ref: null, status: 'recorded', occurred_at: null }],
      refund_transactions: [{ id: 'rt-1', payment_id: 'p-1', external_operation_id: 're_1', kind: 'refund', status: 'succeeded', currency: 'EUR', currency_exponent: 2, amount_minor: '9223372036854775807', occurred_at: null }],
      refund_allocations: [
        { id: 'ra-1', refund_id: 'rf-1', refund_transaction_id: 'rt-1', payment_allocation_id: 'pa-1', currency: 'EUR', amount_minor: '1000', strategy: 'manual', created_by: null, revoked_at: null, created_at: '' },
        { id: 'ra-2', refund_id: 'rf-1', refund_transaction_id: 'rt-1', payment_allocation_id: 'pa-1', currency: 'EUR', amount_minor: '5000', strategy: 'manual', created_by: null, revoked_at: '2026-10-09T00:00:00Z', created_at: '' },
      ],
    };
  }

  it('takes the smaller unlinked remainder with exact bigint arithmetic and ignores revoked links', () => {
    expect(linkableRefundAmount(order(), 'rf-1', 'rt-1')).toBe('9223372036854774000');
  });

  it('returns null when nothing is left or the pair does not exist', () => {
    const value = order();
    value.refund_allocations[0]!.amount_minor = '9223372036854775000';

    expect(linkableRefundAmount(value, 'rf-1', 'rt-1')).toBeNull();
    expect(linkableRefundAmount(order(), 'rf-1', 'missing')).toBeNull();
  });
});
