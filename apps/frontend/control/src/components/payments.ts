import type { Tone } from '@bw/ui';
import type { PaymentStatus } from '@/api';

export const PAYMENT_TONES: Record<PaymentStatus, Tone> = {
  draft: 'unknown',
  ready: 'unknown',
  billed: 'note',
  paid: 'ok',
  completed: 'ok',
  canceled: 'unknown',
  past_due: 'crit',
};
