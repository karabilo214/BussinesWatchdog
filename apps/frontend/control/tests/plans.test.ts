import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { parseMinor } from '@bw/ui';
import * as api from '@/api';
import type { PlanCatalog, PlanVersion, StaffMe } from '@/api';
import App from '@/App.vue';
import { useStaff } from '@/composables/useStaff';
import { localization } from '@/i18n';
import { buildRouter } from '@/router';

vi.mock('@/api', () => ({
  fetchMe: vi.fn(),
  login: vi.fn(),
  submitMfa: vi.fn(),
  logout: vi.fn(),
  fetchPlans: vi.fn(),
  createPlan: vi.fn(),
  createVersion: vi.fn(),
  updateVersion: vi.fn(),
  addPrice: vi.fn(),
  removePrice: vi.fn(),
  publishVersion: vi.fn(),
  retireVersion: vi.fn(),
  fetchDiscounts: vi.fn(),
  createDiscount: vi.fn(),
  updateDiscount: vi.fn(),
  archiveDiscount: vi.fn(),
  lookupTenants: vi.fn(),
  fetchDiscountApplications: vi.fn(),
}));

const mocked = vi.mocked(api);

function staff(capabilities: string[]): StaffMe {
  return { staff_id: 's-1', name: 'Founder', first_name: null, last_name: 'Founder', photo_url: null, email: 'f@example.test', locale: 'ru', role: 'platform_owner', mfa_enabled: true, capabilities, billing_mode: 'test', session: { idle_minutes: 30, absolute_hours: 8 } };
}

const DRAFT: PlanVersion = {
  id: 'v-1',
  version: 1,
  state: 'draft',
  name: { ru: 'Команда', en: 'Team' },
  description: {},
  placeholder: false,
  trial_days: 14,
  order_volume_grace_days: 30,
  contact_sales: false,
  faster_check_interval_minutes: null,
  entitlements: { features: ['checkout_monitoring'], limits: { max_active_stores: 2 } },
  published_at: null,
  subscribers: 0,
  prices: [],
};

function catalog(version: PlanVersion = DRAFT): PlanCatalog {
  return {
    data: [{ id: 'p-1', code: 'team', state: version.state === 'draft' ? 'draft' : 'published', versions: [version] }],
    mode: 'test',
    provider_configured: true,
    catalog: {
      features: ['checkout_monitoring', 'money_reconciliation'],
      limits: ['max_active_stores', 'monthly_event_budget', 'max_orders_per_month'],
      per_store_limits: ['monthly_event_budget'],
      currencies: ['EUR', 'JPY'],
      intervals: ['day', 'week', 'month', 'year'],
      max_interval_count: 365,
      max_trial_days: 365,
    },
  };
}

async function open(path: string, capabilities = ['plans.manage', 'plans.read']) {
  mocked.fetchMe.mockResolvedValue(staff(capabilities));
  await useStaff().load(true);
  const router = buildRouter();
  await router.push(path);
  await router.isReady();
  const wrapper = mount(App, { global: { plugins: [localization, router] } });
  await flushPromises();
  await vi.waitFor(() => expect(wrapper.find('h1').exists()).toBe(true));
  await flushPromises();

  return wrapper;
}

beforeEach(() => {
  vi.clearAllMocks();
  useStaff().forget();
  localization.setLocale('ru');
  mocked.fetchPlans.mockResolvedValue(catalog());
});

describe('parseMinor', () => {
  it('turns typed amounts into minor units with string arithmetic only', () => {
    expect(parseMinor('19,90', 2)).toBe('1990');
    expect(parseMinor('19.9', 2)).toBe('1990');
    expect(parseMinor('1 200', 2)).toBe('120000');
    expect(parseMinor('0,05', 2)).toBe('5');
    expect(parseMinor('1500', 0)).toBe('1500');
    expect(parseMinor('19,999', 2)).toBeNull();
    expect(parseMinor('-1', 2)).toBeNull();
    expect(parseMinor('1e3', 2)).toBeNull();
    expect(parseMinor('9999999999999', 2)).toBeNull();
  });
});

describe('plans', () => {
  it('lists plans with their versions and state', async () => {
    const wrapper = await open('/owner/plans');

    expect(wrapper.get('[data-plan="team"]').text()).toContain('Команда');
    expect(wrapper.get('[data-plan="team"]').text()).toContain('Черновик');
    expect(wrapper.find('[data-action="new-plan"]').exists()).toBe(true);
  });

  it('saves the draft with unlimited limits as null and adds a price for any period', async () => {
    mocked.updateVersion.mockResolvedValue(DRAFT);
    mocked.addPrice.mockResolvedValue(DRAFT);
    const wrapper = await open('/owner/plans/versions/v-1');

    await wrapper.get('[data-field="name-de"]').setValue('Team');
    await wrapper.get('[data-feature="money_reconciliation"]').setValue(true);
    await wrapper.get('[data-limit="max_orders_per_month"] input[type="checkbox"]').setValue(false);
    await wrapper.get('[data-limit="max_orders_per_month"] input:not([type="checkbox"])').setValue('1000');
    await wrapper.get('[data-field="grace-days"]').setValue(21);
    await wrapper.get('[data-field="contact-sales"]').setValue(true);
    await wrapper.get('[data-field="faster-minutes"]').setValue(5);
    expect(wrapper.text()).toContain('Заказов в месяц на магазин');
    await wrapper.get('[data-action="save"]').trigger('click');
    await flushPromises();

    expect(mocked.updateVersion).toHaveBeenCalledWith('v-1', {
      name: { ru: 'Команда', en: 'Team', de: 'Team' },
      description: { ru: '', en: '', de: '' },
      trial_days: 14,
      order_volume_grace_days: 21,
      contact_sales: true,
      faster_check_interval_minutes: 5,
      entitlements: { features: ['checkout_monitoring', 'money_reconciliation'], limits: { max_active_stores: 2, monthly_event_budget: null, max_orders_per_month: 1000 } },
    });

    await wrapper.get('[data-field="amount"]').setValue('45,50');
    await wrapper.get('[data-field="count"]').setValue(2);
    await wrapper.get('[data-field="interval"]').setValue('week');
    expect(wrapper.get('[data-form="price"]').text()).toContain('каждые 2 недели');
    await wrapper.get('[data-form="price"]').trigger('submit');
    await flushPromises();

    expect(mocked.addPrice).toHaveBeenCalledWith('v-1', { kind: 'base', currency: 'EUR', amount_minor: '4550', interval: 'week', interval_count: 2 });
  });

  it('publishes only with a reason and an authenticator code', async () => {
    mocked.publishVersion.mockResolvedValue({ ...DRAFT, state: 'published' });
    const wrapper = await open('/owner/plans/versions/v-1');

    await wrapper.get('[data-action="publish"]').trigger('click');
    expect(wrapper.get('[data-action="confirm"]').attributes('disabled')).toBeDefined();
    await wrapper.get('[data-field="reason"]').setValue('Launch');
    await wrapper.get('[data-field="step-up"]').setValue('123456');
    await wrapper.get('[data-form="confirm"]').trigger('submit');
    await flushPromises();

    expect(mocked.publishVersion).toHaveBeenCalledWith('v-1', 'Launch', '123456');
  });

  it('shows a published version read-only to staff who can only read plans', async () => {
    mocked.fetchPlans.mockResolvedValue(catalog({ ...DRAFT, state: 'published', subscribers: 3 }));
    const wrapper = await open('/owner/plans/versions/v-1', ['plans.read']);

    expect(wrapper.text()).toContain('Подписчиков на ней: 3');
    expect(wrapper.get('[data-field="name-ru"]').attributes('disabled')).toBeDefined();
    expect(wrapper.find('[data-action="save"]').exists()).toBe(false);
    expect(wrapper.find('[data-panel="actions"]').exists()).toBe(false);
  });
});

describe('discounts', () => {
  it('creates a discount for chosen organisations after the confirmation code', async () => {
    mocked.fetchDiscounts.mockResolvedValue({ data: [], mode: 'test' });
    mocked.lookupTenants.mockResolvedValue([{ id: 't-1', name: 'Kaffee Lindner' }]);
    mocked.createDiscount.mockResolvedValue({} as never);
    const wrapper = await open('/owner/discounts');

    await wrapper.get('[data-action="new-discount"]').trigger('click');
    await wrapper.get('[data-field="name"]').setValue('Agentur Lindner');
    await wrapper.get('[data-audience="tenant"]').setValue(true);
    await wrapper.get('[data-field="tenant-search"]').setValue('lindner');
    await wrapper.get('[data-action="find-tenants"]').trigger('click');
    await flushPromises();
    await wrapper.get('[data-add-tenant="Kaffee Lindner"]').trigger('click');
    await wrapper.get('[data-field="percent"]').setValue('12,5');
    await wrapper.get('[data-field="duration"]').setValue('periods');
    await wrapper.get('[data-field="periods"]').setValue(6);
    await wrapper.get('[data-plan-option="team"]').setValue(true);
    await wrapper.get('[data-form="discount"]').trigger('submit');
    expect(mocked.createDiscount).not.toHaveBeenCalled();

    await wrapper.get('[data-field="reason"]').setValue('Partner deal');
    await wrapper.get('[data-field="step-up"]').setValue('123456');
    await wrapper.get('[data-form="confirm"]').trigger('submit');
    await flushPromises();

    expect(mocked.createDiscount).toHaveBeenCalledWith(
      expect.objectContaining({ name: 'Agentur Lindner', audience: 'tenant', code: null, kind: 'percentage', percent_bps: 1250, amount_minor: null, duration: 'periods', duration_periods: 6, usage_limit: null, plan_ids: ['p-1'], tenant_ids: ['t-1'] }),
      { reason: 'Partner deal', confirmation_code: '123456' },
    );
  });
});

describe('discount history', () => {
  it('shows each discount of a payment in order with what it saved', async () => {
    mocked.fetchDiscountApplications.mockResolvedValue({
      data: [
        {
          id: 'a-1', period_number: 2, period_start: '2026-11-12T10:00:00Z', currency: 'EUR', currency_exponent: 2, amount_before_minor: '3700', saving_minor: '1036', amount_after_minor: '2664',
          status: 'applied', error_code: null, tenant: { id: 't-1', name: 'Kaffee Lindner' }, created_at: '2026-10-12T10:00:00Z', applied_at: '2026-10-12T10:00:00Z',
          lines: [
            { discount: { id: 'd-1', name: 'Herbst', code: null, audience: 'automatic' }, position: 1, amount_before_minor: '3700', saving_minor: '370', amount_after_minor: '3330' },
            { discount: { id: 'd-2', name: 'Partner', code: null, audience: 'tenant' }, position: 2, amount_before_minor: '3330', saving_minor: '666', amount_after_minor: '2664' },
          ],
        },
      ],
      next_cursor: null,
    });
    const wrapper = await open('/owner/discounts/history');

    const card = wrapper.get('[data-application="2"]');
    expect(card.text()).toContain('Продление, период 2');
    expect(card.get('[data-line="automatic"]').text()).toMatch(/Акция: Herbst · от 37,00\s€\s*−3,70\s€/);
    expect(card.get('[data-line="tenant"]').text()).toMatch(/Личная скидка: Partner · от 33,30\s€\s*−6,66\s€/);
    expect(card.text()).toMatch(/Итого \(скидка 10,36\s€\)\s*26,64\s€/);
  });
});
