import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createMemoryHistory, createRouter } from 'vue-router';
import * as billing from '@/api/billing';
import type { BillingCatalog, BillingPlan, Entitlements } from '@/api/billing';
import * as stores from '@/api/stores';
import type { AuthSession } from '@/api/types';
import * as paddle from '@/composables/paddle';
import { localization, setLocale } from '@/i18n';
import BillingView from '@/views/BillingView.vue';

const session = ref<AuthSession | null>(null);

vi.mock('@/api/billing', () => ({
  fetchEntitlements: vi.fn(),
  fetchPlans: vi.fn(),
  fetchSubscription: vi.fn(),
  startTrial: vi.fn(),
  startCheckout: vi.fn(),
  fetchCheckout: vi.fn(),
  checkDiscount: vi.fn(),
}));
vi.mock('@/api/stores', () => ({ listStores: vi.fn() }));
vi.mock('@/composables/paddle', () => ({ openPaddleCheckout: vi.fn() }));
vi.mock('@/composables/useSession', () => ({
  useSession: () => ({ session, load: vi.fn(async () => session.value), signOut: vi.fn() }),
}));

const api = vi.mocked(billing);

function sessionFor(role: 'owner' | 'admin' | 'viewer'): AuthSession {
  return {
    user: { id: 'u-me', name: 'Me', email: 'me@example.test', locale: 'ru', email_verified: true, mfa_enabled: false },
    active_tenant_id: 't-1',
    memberships: [{ tenant_id: 't-1', role, created_at: '2026-10-01T00:00:00Z' }],
  };
}

function entitlements(overrides: Partial<Entitlements> = {}): Entitlements {
  return {
    access_state: 'pilot',
    reason_codes: [],
    enforced: false,
    plan_code: null,
    features: [],
    limits: { max_active_stores: null, manual_runs_per_day: null, monthly_event_budget: null },
    usage: {
      manual_runs_per_day: { used: 1, period_start: '2026-10-11T21:00:00Z', period_end: '2026-10-12T21:00:00Z' },
      monthly_event_budget: { used: 120, period_start: '2026-09-30T21:00:00Z', period_end: '2026-10-31T22:00:00Z' },
    },
    ...overrides,
  };
}

const START: BillingPlan = {
  plan_code: 'start',
  plan_version_id: 'v-start',
  name: { ru: 'Старт', en: 'Start' },
  placeholder: true,
  trial_days: 14,
  entitlements: { features: ['checkout_monitoring'], limits: { max_active_stores: 1, max_extra_stores: 4, min_check_interval_minutes: 15 } },
  prices: [
    { id: 'p-base', kind: 'base', currency: 'EUR', currency_exponent: 2, amount_minor: '1900', interval: 'month', interval_count: 1, tax_behavior: 'provider_calculated' },
    { id: 'p-extra', kind: 'extra_store', currency: 'EUR', currency_exponent: 2, amount_minor: '900', interval: 'month', interval_count: 1, tax_behavior: 'provider_calculated' },
  ],
  discounts: [],
};

function catalog(overrides: Partial<BillingCatalog> = {}): BillingCatalog {
  return { data: [START], mode: 'test', checkout_available: true, trial_available: true, ...overrides };
}

async function mountView() {
  const router = createRouter({
    history: createMemoryHistory('/app/'),
    routes: ['overview', 'profile', 'team', 'billing', 'incidents', 'reconciliation', 'checks', 'notifications', 'login'].map((name) => ({ path: `/${name}`, name, component: { template: '<div />' } })),
  });
  await router.push('/billing');
  const wrapper = mount(BillingView, { global: { plugins: [localization, router], stubs: { RouterLink: RouterLinkStub } } });
  await flushPromises();

  return wrapper;
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
  vi.mocked(stores.listStores).mockResolvedValue([{ status: 'active' }, { status: 'deleted' }] as never);
  api.fetchEntitlements.mockResolvedValue(entitlements());
  api.fetchPlans.mockResolvedValue(catalog());
  api.fetchSubscription.mockResolvedValue(null);
});

describe('BillingView', () => {
  it('shows the owner the pilot and test notices, usage and a plan with exact prices', async () => {
    session.value = sessionFor('owner');
    const wrapper = await mountView();

    expect(wrapper.find('[data-notice="pilot"]').exists()).toBe(true);
    expect(wrapper.find('[data-notice="test-mode"]').exists()).toBe(true);
    expect(wrapper.get('[data-usage="stores"]').text()).toContain('1 /');
    expect(wrapper.get('[data-usage="events"]').text()).toContain('120');
    const card = wrapper.get('[data-plan="start"]');
    expect(card.text()).toContain('Старт');
    expect(card.text()).toContain('Черновик');
    expect(card.text()).toMatch(/19,00\s€/);
    expect(card.text()).toContain('раз в 15 мин');
    expect(card.text()).toContain('Проверка оформления заказа в браузере');

    await card.get('[data-extra="start"]').setValue(2);
    expect(card.text()).toMatch(/Итого: 37,00\s€ в месяц/);
  });

  it('shows any billing period the owner set', async () => {
    session.value = sessionFor('owner');
    const biweekly: BillingPlan = { ...START, prices: [{ ...START.prices[0]!, interval: 'week', interval_count: 2 }, { ...START.prices[1]!, interval: 'day', interval_count: 21 }] };
    api.fetchPlans.mockResolvedValue(catalog({ data: [biweekly] }));
    const wrapper = await mountView();

    expect(wrapper.get('[data-plan="start"]').text()).toContain('каждые 2 недели');
    expect(wrapper.get('[data-plan="start"]').text()).toContain('каждый 21 день');
  });

  it('starts a trial and reloads the subscription', async () => {
    session.value = sessionFor('owner');
    api.startTrial.mockResolvedValue({} as never);
    const wrapper = await mountView();

    await wrapper.get('[data-action="trial-start"]').trigger('click');
    await flushPromises();

    expect(api.startTrial).toHaveBeenCalledWith('v-start');
    expect(api.fetchSubscription).toHaveBeenCalledTimes(2);
    expect(wrapper.text()).toContain('Пробный период начался.');
  });

  it('opens Paddle for a server-created checkout and trusts only the confirmed backend state', async () => {
    session.value = sessionFor('owner');
    const providerCheckout = { provider: 'paddle', environment: 'sandbox' as const, client_token: 'tok', transaction_id: 'txn_1' };
    api.startCheckout.mockResolvedValue({ id: 'c-1', status: 'pending', error_code: null, expires_at: '', extra_store_quantity: 2, discounts: null, access_state: 'onboarding', provider_checkout: providerCheckout });
    api.fetchCheckout.mockResolvedValue({ id: 'c-1', status: 'confirmed', error_code: null, expires_at: '', extra_store_quantity: 2, discounts: null, access_state: 'active', provider_checkout: null });
    let emit: (name: string) => void = () => undefined;
    vi.mocked(paddle.openPaddleCheckout).mockImplementation(async (_checkout, onEvent) => {
      emit = onEvent;
    });
    const wrapper = await mountView();

    await wrapper.get('[data-extra="start"]').setValue(2);
    await wrapper.get('[data-action="buy-start"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-action="buy-start"]').trigger('click');
    await flushPromises();

    const [first, second] = api.startCheckout.mock.calls;
    expect(first!.slice(0, 2)).toEqual(['p-base', 2]);
    expect(second![2]).toBe(first![2]);
    expect(paddle.openPaddleCheckout).toHaveBeenCalledWith(providerCheckout, expect.any(Function));
    expect(wrapper.find('[data-checkout="confirmed"]').exists()).toBe(false);

    vi.useFakeTimers();
    emit('checkout.completed');
    await flushPromises();

    expect(api.fetchCheckout).toHaveBeenCalledWith('c-1');
    expect(wrapper.find('[data-checkout="confirming"]').exists()).toBe(true);

    api.fetchSubscription.mockResolvedValue({ provider: 'paddle', status: 'active', plan_version_id: 'v-start', plan_code: 'start' } as never);
    await vi.advanceTimersByTimeAsync(3000);
    await flushPromises();
    vi.useRealTimers();

    expect(wrapper.find('[data-checkout="confirmed"]').exists()).toBe(true);
  });

  it('shows members the plan without actions and does not read the owner-only subscription', async () => {
    session.value = sessionFor('viewer');
    api.fetchEntitlements.mockResolvedValue(entitlements({ enforced: true, access_state: 'active', plan_code: 'start' }));
    const wrapper = await mountView();

    expect(api.fetchSubscription).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('Подпиской управляет владелец организации.');
    expect(wrapper.find('[data-action="buy-start"]').exists()).toBe(false);
    expect(wrapper.find('[data-action="trial-start"]').exists()).toBe(false);
  });

  it('warns softly when the monthly event volume is exceeded', async () => {
    session.value = sessionFor('owner');
    api.fetchEntitlements.mockResolvedValue(entitlements({ enforced: true, access_state: 'active', limits: { monthly_event_budget: 100 } }));
    const wrapper = await mountView();

    expect(wrapper.get('[data-usage="events"]').text()).toContain('События по-прежнему принимаются');
  });

  it('applies a promo code, shows the discounted first payment and sends the code with the purchase', async () => {
    session.value = sessionFor('owner');
    api.checkDiscount.mockResolvedValue({ id: 'd-1', position: 3, audience: 'code', label: { ru: 'Запуск' }, kind: 'percentage', percent: '12.5', amount_minor: null, currency: null, currency_exponent: null, duration: 'periods', duration_periods: 3, expires_at: null });
    api.startCheckout.mockResolvedValue({ id: 'c-1', status: 'pending', error_code: null, expires_at: '', extra_store_quantity: 0, discounts: null, access_state: 'onboarding', provider_checkout: null });
    api.fetchCheckout.mockResolvedValue({ id: 'c-1', status: 'pending', error_code: null, expires_at: '', extra_store_quantity: 0, discounts: null, access_state: 'onboarding', provider_checkout: null });
    const wrapper = await mountView();

    await wrapper.get('[data-field="promo"]').setValue('launch');
    await wrapper.get('[data-form="promo"]').trigger('submit');
    await flushPromises();

    expect(api.checkDiscount).toHaveBeenCalledWith('launch', 'p-base');
    expect(wrapper.get('[data-promo="applied"]').text()).toContain('LAUNCH');
    expect(wrapper.get('[data-discount-line="code"]').text()).toMatch(/Промокод «Запуск»: скидка 12,5\s% на 3 периодов\s*−2,38\s€/);
    expect(wrapper.get('[data-total="discounted"]').text()).toMatch(/19,00\s€\s+16,62\s€/);

    await wrapper.get('[data-action="buy-start"]').trigger('click');
    await flushPromises();
    expect(api.startCheckout).toHaveBeenCalledWith('p-base', 0, expect.any(String), 'launch');
  });

  it('explains a promo code that does not work', async () => {
    session.value = sessionFor('owner');
    const { ApiError } = await import('@bw/api-client');
    api.checkDiscount.mockRejectedValue(new ApiError(422, 'discount_expired'));
    const wrapper = await mountView();

    await wrapper.get('[data-field="promo"]').setValue('OLD');
    await wrapper.get('[data-form="promo"]').trigger('submit');
    await flushPromises();

    expect(wrapper.text()).toContain('Срок действия промокода закончился.');
    expect(wrapper.find('[data-total="discounted"]').exists()).toBe(false);
  });

  it('applies the offer, then the organisation\'s own discount, without a code', async () => {
    session.value = sessionFor('owner');
    api.fetchPlans.mockResolvedValue(
      catalog({
        data: [
          {
            ...START,
            discounts: [
              { id: 'd-3', position: 2, audience: 'tenant', label: {}, kind: 'percentage', percent: '15', amount_minor: null, currency: null, currency_exponent: null, duration: 'forever', duration_periods: null, expires_at: null },
              { id: 'd-2', position: 1, audience: 'automatic', label: { ru: 'Осень' }, kind: 'flat', percent: null, amount_minor: '500', currency: 'EUR', currency_exponent: 2, duration: 'once', duration_periods: null, expires_at: null },
            ],
          },
        ],
      }),
    );
    const wrapper = await mountView();

    const lines = wrapper.findAll('[data-discount-line]');
    expect(lines.map((line) => line.attributes('data-discount-line'))).toEqual(['automatic', 'tenant']);
    expect(lines[0]!.text()).toMatch(/Акция «Осень»: скидка 5,00\s€ на первый платёж\s*−5,00\s€/);
    expect(lines[1]!.text()).toMatch(/Ваша скидка: скидка 15\s% навсегда\s*−2,10\s€/);
    expect(wrapper.get('[data-total="discounted"]').text()).toMatch(/19,00\s€\s+11,90\s€/);
  });
});
