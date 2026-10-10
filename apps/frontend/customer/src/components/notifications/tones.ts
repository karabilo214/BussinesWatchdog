import type { Tone } from '@bw/ui';
import type { DeliveryStatus, NotificationChannel } from '@/api/types';

/** "uncertain" is not "sent": the provider did not confirm, and it is not retried to avoid duplicates. */
export const DELIVERY_TONES: Record<DeliveryStatus, Tone> = {
  sent: 'ok',
  queued: 'note',
  sending: 'note',
  uncertain: 'warn',
  failed: 'warn',
  dead_letter: 'crit',
  suppressed: 'unknown',
};

export type ChannelState = 'active' | 'disabled' | 'unverified' | 'failing';

export function channelState(channel: NotificationChannel): ChannelState {
  if (channel.verified_at === null) return 'unverified';
  if (!channel.enabled) return 'disabled';
  if (channel.health.status === 'failing') return 'failing';

  return 'active';
}

export const CHANNEL_TONES: Record<ChannelState, Tone> = { active: 'ok', disabled: 'unknown', unverified: 'warn', failing: 'crit' };
