import type { Tone } from '@bw/ui';
import type { IntegrationStatus } from '@/api/types';

export const INTEGRATION_TONES: Record<IntegrationStatus, Tone> = {
  active: 'ok',
  degraded: 'warn',
  pending: 'unknown',
  revoked: 'unknown',
  disabled: 'unknown',
};

const PROVIDER_NAMES: Record<string, string> = { woocommerce: 'WooCommerce', stripe: 'Stripe', paypal: 'PayPal' };

export function providerName(provider: string): string {
  return PROVIDER_NAMES[provider] ?? provider;
}
