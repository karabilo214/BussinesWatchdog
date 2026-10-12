import type { Tone } from '@bw/ui';
import type { AccessState, ContactStatus, SubscriptionStatus } from '@/api';

export const ACCESS_TONES: Record<AccessState, Tone> = {
  pilot: 'note',
  active: 'ok',
  override: 'note',
  trial: 'note',
  grace: 'warn',
  paused_billing: 'crit',
  paused_manual: 'crit',
  no_service: 'crit',
  onboarding: 'unknown',
};

export const SUBSCRIPTION_TONES: Record<SubscriptionStatus, Tone> = {
  trial: 'note',
  active: 'ok',
  past_due: 'warn',
  suspended: 'crit',
  cancelled: 'unknown',
};

export const CONTACT_TONES: Record<ContactStatus, Tone> = {
  new: 'warn',
  in_progress: 'note',
  closed: 'unknown',
};
