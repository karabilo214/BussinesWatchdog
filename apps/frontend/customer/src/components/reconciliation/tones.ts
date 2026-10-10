import type { Tone } from '@bw/ui';
import type { FindingStatus } from '@/api/types';

/** Only a verified match is green; "pending" is still inside the grace period, not a problem yet. */
export const FINDING_TONES: Record<FindingStatus, Tone> = {
  ok: 'ok',
  mismatch: 'crit',
  pending: 'note',
  unsupported: 'unknown',
  unknown: 'unknown',
};

export const FINDING_STATUSES: FindingStatus[] = ['mismatch', 'pending', 'unknown', 'unsupported', 'ok'];

export const RULE_CODES = [
  'MONEY_CAPTURE_MISSING',
  'MONEY_CAPTURE_AMOUNT',
  'MONEY_MULTIPLE_CAPTURES',
  'MONEY_CURRENCY_MISMATCH',
  'MONEY_ORDER_CHANGED',
  'MONEY_REFUND_MISSING',
  'MONEY_REFUND_EXTRA',
  'MONEY_PAYMENT_WITHOUT_ORDER',
  'MONEY_UNSUPPORTED',
];
