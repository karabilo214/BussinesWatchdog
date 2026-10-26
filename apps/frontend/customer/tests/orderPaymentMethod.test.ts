import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { getOrder } from '@/api/reconciliation';
import type { OrderDetail } from '@/api/types';
import { localization, setLocale } from '@/i18n';
import OrderView from '@/views/OrderView.vue';

vi.mock('@/api/reconciliation', () => ({ getOrder: vi.fn(), allocateRefund: vi.fn(), revokePaymentAllocation: vi.fn(), revokeRefundAllocation: vi.fn() }));
vi.mock('@/api/stores', () => ({ getStore: vi.fn().mockResolvedValue(null) }));

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
});

it('shows the shop name of a custom payment method in the customer order card', async () => {
  const order: OrderDetail = {
    id: 'o-1', store_id: 's-1', external_id: '1001', display_number: '#1001', status: 'processing', gateway: 'merchant_custom', gateway_name: 'Оплата в магазине', mode: 'live',
    currency: 'EUR', currency_exponent: 2, total_minor: '18400', payment_expected: true, paid_marked_at: null, transaction_ref: null,
    financial_support: 'unsupported', is_synthetic: false, source_created_at: null, source_updated_at: null,
    findings: [], captures: [], revisions: [], allocations: [], refunds: [], refund_transactions: [], refund_allocations: [],
  };
  vi.mocked(getOrder).mockResolvedValue(order);
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', name: 'reconciliation', component: { template: '<div />' } }] });
  await router.push('/');
  const wrapper = mount(OrderView, { props: { id: 'o-1' }, global: { plugins: [localization, router], stubs: { AppLayout: { template: '<div><slot /></div>' }, RouterLink: RouterLinkStub } } });
  await flushPromises();

  const method = wrapper.get('[data-gateway="merchant_custom"]');
  expect(method.text()).toBe('Оплата в магазине');
  expect(method.attributes('title')).toBe('merchant_custom');
  expect(method.text()).not.toContain('merchant_custom');
  wrapper.unmount();
});
