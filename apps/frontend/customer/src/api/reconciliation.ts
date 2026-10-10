import { newIdempotencyKey, request } from '@bw/api-client';
import type { Finding, FindingStatus, OrderDetail, Page, PaymentAllocation, RefundAllocation, UnmatchedPayment } from './types';

export interface FindingFilters {
  current?: boolean;
  status?: FindingStatus;
  rule_code?: string;
  currency?: string;
  from?: string;
  to?: string;
  cursor?: string;
}

export async function listFindings(storeId: string, filters: FindingFilters): Promise<Page<Finding>> {
  return (
    await request<Page<Finding>>('GET', `/api/v1/stores/${encodeURIComponent(storeId)}/findings`, {
      query: { ...filters, current: filters.current ? 1 : undefined, limit: 50 },
    })
  ).data;
}

export async function listUnmatchedPayments(storeId: string, cursor?: string): Promise<Page<UnmatchedPayment>> {
  return (await request<Page<UnmatchedPayment>>('GET', `/api/v1/stores/${encodeURIComponent(storeId)}/unmatched-payments`, { query: { cursor, limit: 50 } })).data;
}

export async function getOrder(id: string): Promise<OrderDetail> {
  return (await request<OrderDetail>('GET', `/api/v1/orders/${encodeURIComponent(id)}`)).data;
}

export async function allocatePayment(input: {
  order_id: string;
  payment_id: string;
  capture_transaction_id: string;
  amount_minor: string;
  currency: string;
  reason: string;
}): Promise<PaymentAllocation> {
  return (await request<PaymentAllocation>('POST', '/api/v1/payment-allocations', { body: input, idempotencyKey: newIdempotencyKey() })).data;
}

export async function revokePaymentAllocation(id: string, reason: string): Promise<void> {
  await request('POST', `/api/v1/payment-allocations/${encodeURIComponent(id)}/revoke`, { body: { reason } });
}

export async function allocateRefund(input: {
  refund_id: string;
  refund_transaction_id: string;
  payment_allocation_id: string;
  amount_minor: string;
  currency: string;
  reason: string;
}): Promise<RefundAllocation> {
  return (await request<RefundAllocation>('POST', '/api/v1/refund-allocations', { body: input, idempotencyKey: newIdempotencyKey() })).data;
}

export async function revokeRefundAllocation(id: string, reason: string): Promise<void> {
  await request('POST', `/api/v1/refund-allocations/${encodeURIComponent(id)}/revoke`, { body: { reason } });
}
