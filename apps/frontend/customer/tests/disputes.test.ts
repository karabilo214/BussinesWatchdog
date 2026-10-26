import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { Dispute } from '@/api/types';
import DisputeCard from '@/components/disputes/DisputeCard.vue';
import { localization, setLocale } from '@/i18n';

const dispute = (overrides: Partial<Dispute> = {}): Dispute => ({
  id: 'd-1', external_id: 'du_1Example', payment_id: 'p-1', provider: 'stripe', mode: 'live', status: 'needs_response', open: true,
  reason: 'product_not_received', currency: 'EUR', currency_exponent: 2, amount_minor: '8900', money_amounts_hidden: false,
  evidence_due_by: '2026-11-05T23:59:59Z', opened_at: '2026-10-26T09:00:00Z', closed_at: null, ...overrides,
});

describe('dispute card', () => {
  it('shows the reason, the amount and the answer deadline of an open dispute', () => {
    setLocale('de');
    const wrapper = mount(DisputeCard, { props: { dispute: dispute() }, global: { plugins: [localization] } });

    expect(wrapper.text()).toContain('Rückbuchung (Chargeback)');
    expect(wrapper.text()).toContain('Ware nicht erhalten');
    expect(wrapper.find('[data-dispute-amount]').text()).toContain('89,00');
    expect(wrapper.find('[data-dispute-due]').exists()).toBe(true);
    expect(wrapper.find('[data-tone="warn"]').exists()).toBe(true);
  });

  it('hides the amount without money.view and shows a lost dispute as closed', () => {
    setLocale('ru');
    const wrapper = mount(DisputeCard, {
      props: { dispute: dispute({ status: 'lost', open: false, amount_minor: null, money_amounts_hidden: true, reason: 'something_new', closed_at: '2026-11-20T10:00:00Z' }) },
      global: { plugins: [localization] },
    });

    expect(wrapper.find('[data-dispute-amount]').exists()).toBe(false);
    expect(wrapper.find('[data-dispute-due]').exists()).toBe(false);
    expect(wrapper.find('[data-tone="crit"]').text()).toBe('проигран');
    expect(wrapper.text()).toContain('не указана');
  });
});
