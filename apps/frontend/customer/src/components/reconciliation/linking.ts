import type { OrderDetail } from '@/api/types';

/** Remaining amount of a minor-unit total after active links, computed with BigInt (never a float). */
function remaining(total: string, linked: string[]): bigint {
  return linked.reduce((rest, value) => rest - BigInt(value), BigInt(total));
}

/** What can still be linked between a store refund and a provider refund: the smaller unlinked remainder, or null. */
export function linkableRefundAmount(order: OrderDetail, refundId: string, transactionId: string): string | null {
  const refund = order.refunds.find((item) => item.id === refundId);
  const transaction = order.refund_transactions.find((item) => item.id === transactionId);

  if (refund === undefined || transaction === undefined || refund.currency !== transaction.currency) return null;

  const active = order.refund_allocations.filter((item) => item.revoked_at === null);
  const refundRest = remaining(refund.amount_minor, active.filter((item) => item.refund_id === refund.id).map((item) => item.amount_minor));
  const transactionRest = remaining(transaction.amount_minor, active.filter((item) => item.refund_transaction_id === transaction.id).map((item) => item.amount_minor));
  const value = refundRest < transactionRest ? refundRest : transactionRest;

  return value > 0n ? value.toString() : null;
}
