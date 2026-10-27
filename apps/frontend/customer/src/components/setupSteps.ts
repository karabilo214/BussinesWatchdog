import type { RouteLocationRaw } from 'vue-router';
import type { BrowserChecksState, CheckoutScriptState, Store } from '@/api/types';

export interface SetupStep {
  key: 'source' | 'payment' | 'observation' | 'browser' | 'telegram';
  label: string;
  to: RouteLocationRaw;
}

const SCRIPT_TARGETS: Partial<Record<CheckoutScriptState, 'plugin' | 'source' | 'scenario'>> = {
  needs_plugin: 'plugin',
  needs_app: 'source',
  connection_without_script: 'source',
  app_not_active: 'source',
  check_does_not_reach_payment: 'scenario',
};

const BROWSER_TARGETS: Partial<Record<BrowserChecksState, 'verification' | 'readiness' | 'scenario'>> = {
  store_not_verified: 'verification',
  disabled: 'readiness',
  not_configured: 'scenario',
  scenario_disabled: 'scenario',
};

/** What is not yet connected or set up for the store, each with a link to the block where it is done. */
export function setupSteps(store: Store): SetupStep[] {
  const onStore = (hash: string): RouteLocationRaw => ({ name: 'store', params: { id: store.id }, hash });
  const onChecks = (hash: string): RouteLocationRaw => ({ name: 'checks', query: { store: store.id }, hash });
  const steps: SetupStep[] = [];

  if (!store.onboarding.source_connected) steps.push({ key: 'source', label: 'store.more.source', to: onStore('#order-source') });
  if (!store.onboarding.provider_connected && store.coverage.money.providers.length === 0) steps.push({ key: 'payment', label: 'store.more.payment', to: onStore('#payment-providers') });

  const script = store.coverage.checkout_script ? SCRIPT_TARGETS[store.coverage.checkout_script.state] : undefined;

  if (script === 'plugin') steps.push({ key: 'observation', label: 'store.more.observation', to: onStore('#woocommerce-plugin') });
  if (script === 'source') steps.push({ key: 'observation', label: 'store.more.observation', to: onStore('#order-source') });
  if (script === 'scenario') steps.push({ key: 'observation', label: 'store.more.observation', to: onChecks('#check-scenario') });

  const browser = BROWSER_TARGETS[store.coverage.browser_checks.state];

  if (browser === 'verification') steps.push({ key: 'browser', label: 'store.more.browser', to: onStore('#store-verification') });
  if (browser === 'readiness') steps.push({ key: 'browser', label: 'store.more.browser', to: onChecks('#check-readiness') });
  if (browser === 'scenario') steps.push({ key: 'browser', label: 'store.more.browser', to: onChecks('#check-scenario') });

  if (!store.onboarding.telegram_notifications_ready) steps.push({ key: 'telegram', label: 'store.more.telegram', to: { name: 'notifications', query: { add: 'telegram' }, hash: '#new-channel' } });

  return steps;
}
