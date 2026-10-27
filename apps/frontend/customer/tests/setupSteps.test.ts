import { describe, expect, it } from 'vitest';
import type { BrowserChecksState, CheckoutScriptState, Store } from '@/api/types';
import { setupSteps } from '@/components/setupSteps';
import { makeStore } from './fixtures';

function store(script: CheckoutScriptState | null, browser: BrowserChecksState, onboarding: Partial<Store['onboarding']> = {}): Store {
  const base = makeStore();

  return makeStore({
    coverage: {
      ...base.coverage,
      ...(script ? { checkout_script: { state: script, last_report_at: null } } : {}),
      browser_checks: { ...base.coverage.browser_checks, state: browser },
    },
    onboarding: { ...base.onboarding, ...onboarding },
  });
}

const ready = { source_connected: true, access_verified: true, provider_connected: true, telegram_notifications_ready: true };

describe('what else can be connected', () => {
  it('lists nothing when everything is connected and set up', () => {
    expect(setupSteps(store('observing', 'passing', ready))).toEqual([]);
    expect(setupSteps(store('not_available', 'failing', ready))).toEqual([]);
    expect(setupSteps(store('elements_not_found', 'scheduled', ready))).toEqual([]);
  });

  it('lists only the missing parts, each linked to the block where it is set up', () => {
    const steps = setupSteps(store('needs_plugin', 'store_not_verified'));

    expect(steps.map((step) => [step.key, step.to])).toEqual([
      ['source', { name: 'store', params: { id: 's-1' }, hash: '#order-source' }],
      ['payment', { name: 'store', params: { id: 's-1' }, hash: '#payment-providers' }],
      ['observation', { name: 'store', params: { id: 's-1' }, hash: '#woocommerce-plugin' }],
      ['browser', { name: 'store', params: { id: 's-1' }, hash: '#store-verification' }],
      ['telegram', { name: 'notifications', query: { add: 'telegram' }, hash: '#new-channel' }],
    ]);
  });

  it('drops a connected payment provider and Telegram', () => {
    const keys = setupSteps(store('needs_plugin', 'passing', { source_connected: true, provider_connected: true, telegram_notifications_ready: true })).map((step) => step.key);

    expect(keys).toEqual(['observation']);
  });

  it('does not offer a payment provider that is connected but needs attention', () => {
    const degraded = store('observing', 'passing', { ...ready, provider_connected: false });
    degraded.coverage.money.providers = [{ provider: 'stripe', status: 'degraded' }];

    expect(setupSteps(degraded)).toEqual([]);
  });

  it('sends checkout observation to the connection or to the check scenario', () => {
    const target = (state: CheckoutScriptState) => setupSteps(store(state, 'passing', ready)).find((step) => step.key === 'observation')?.to;

    expect(target('needs_app')).toEqual({ name: 'store', params: { id: 's-1' }, hash: '#order-source' });
    expect(target('app_not_active')).toEqual({ name: 'store', params: { id: 's-1' }, hash: '#order-source' });
    expect(target('check_does_not_reach_payment')).toEqual({ name: 'checks', query: { store: 's-1' }, hash: '#check-scenario' });
    expect(target('not_in_plan')).toBeUndefined();
  });

  it('sends the browser check to the checks page where it is turned on or configured', () => {
    const target = (state: BrowserChecksState) => setupSteps(store(null, state, ready)).find((step) => step.key === 'browser')?.to;

    expect(target('disabled')).toEqual({ name: 'checks', query: { store: 's-1' }, hash: '#check-readiness' });
    expect(target('not_configured')).toEqual({ name: 'checks', query: { store: 's-1' }, hash: '#check-scenario' });
    expect(target('scenario_disabled')).toEqual({ name: 'checks', query: { store: 's-1' }, hash: '#check-scenario' });
    expect(target('capacity_paused')).toBeUndefined();
  });
});
