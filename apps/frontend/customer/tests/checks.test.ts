import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api/checks';
import type { CheckScenario, Store } from '@/api/types';
import RunsList from '@/components/checks/RunsList.vue';
import ScenarioPanel from '@/components/checks/ScenarioPanel.vue';
import { ATTEMPT_TONES, RUN_TONES, STEP_TONES } from '@/components/checks/tones';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';
import { localization, setLocale } from '@/i18n';
import { makeStore } from './fixtures';

vi.mock('@/api/checks', () => ({
  createScenario: vi.fn(),
  updateScenario: vi.fn(),
  listRuns: vi.fn(),
  startRun: vi.fn(),
}));

const mocked = vi.mocked(api);
const global = { plugins: [localization], stubs: { RouterLink: RouterLinkStub } };
const contract = readFileSync(resolve(__dirname, '../../../../contracts/openapi.yaml'), 'utf8');

function enumAfter(anchor: string, from = 0): string[] {
  const start = contract.indexOf(anchor, from);
  const match = /enum: \[([^\]]+)\]/.exec(contract.slice(start));

  return match![1]!.split(',').map((value) => value.trim());
}

function scenario(overrides: Partial<CheckScenario> = {}): CheckScenario {
  return {
    id: 'sc-1', store_id: 's-1', name: 'Payment form', mode: 'payment_form', version: 2, enabled: true, adapter_version: 'woo-1',
    product_external_id: null, product_url: 'https://kaffee-lindner.example/product/test/', cart_url: null, checkout_url: null,
    extra_allowed_origins: [], synthetic_location: null, platform: 'woocommerce', checkout_access: null, sign_in_configured: false,
    interval_seconds: 900, next_due_at: '2026-10-10T10:15:00Z',
    supported_steps: ['product', 'add_to_cart', 'cart', 'checkout', 'shipping', 'payment_form'],
    untested_components: ['variable_products', 'shipping_required_before_payment', 'third_party_gateway_iframes', 'redirect_gateways'],
    ...overrides,
  };
}

function verifiedStore(): Store {
  return makeStore({ status: 'active', verified_at: '2026-10-01T00:00:00Z', browser_enabled: true });
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
});

describe('check statuses from the contract', () => {
  const checkRun = contract.indexOf('\n    CheckRun:');
  const detail = contract.indexOf('\n    CheckRunDetail:');
  const cases: [string, string[], Record<string, string>, string][] = [
    ['run', enumAfter('status:', checkRun), RUN_TONES, 'status'],
    ['attempt', enumAfter('status:', contract.indexOf('attempt_number: {type: integer}', detail)), ATTEMPT_TONES, 'attempt_status'],
    ['step', enumAfter('status:', contract.indexOf('index: {type: integer}', detail)), STEP_TONES, 'step_status'],
  ];

  for (const [name, states, tones, key] of cases) {
    it(`${name}: every state has a colour and a label, only "passed" is green`, () => {
      expect(new Set(states)).toEqual(new Set(Object.keys(tones)));

      for (const state of states) {
        for (const locale of [ru, en, de]) {
          expect((locale.checks as unknown as Record<string, Record<string, string>>)[key]![state], `${key}.${state}`).toBeTruthy();
        }

        expect(tones[state] === 'ok').toBe(state === 'passed');
      }
    });
  }

  it('every error code a worker or the backend can report is explained', () => {
    const policy = readFileSync(resolve(__dirname, '../../../backend/app/Support/Browser/CheckOutcomePolicy.php'), 'utf8');
    const constants = Object.fromEntries([...policy.matchAll(/const ([A-Z_]+) = '([a-z_]+)'/g)].map((match) => [match[1], match[2]]));
    const list = policy.slice(policy.indexOf('ERROR_CODES = ['), policy.indexOf('];', policy.indexOf('ERROR_CODES = [')));
    const codes = [...list.matchAll(/self::([A-Z_]+)|'([a-z_]+)'/g)].map((match) => (match[1] ? constants[match[1]] : match[2])!);

    for (const code of [...codes, 'lease_expired', 'cancelled_by_user']) {
      expect((en.checks.error as Record<string, string>)[code], code).toBeTruthy();
    }
  });
});

describe('ScenarioPanel', () => {
  it('creates a scenario from the product page, enabled only for a verified store', async () => {
    mocked.createScenario.mockResolvedValue(scenario());
    const wrapper = mount(ScenarioPanel, { props: { store: verifiedStore(), scenario: null, canManage: true }, global });

    await wrapper.get('#scenario-product').setValue('https://kaffee-lindner.example/product/test/');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(mocked.createScenario).toHaveBeenCalledWith('s-1', {
      product_url: 'https://kaffee-lindner.example/product/test/',
      cart_url: null,
      checkout_url: null,
      interval_seconds: 900,
      enabled: true,
      extra_allowed_origins: [],
      synthetic_location: null,
    });
    expect(wrapper.emitted('saved')).toHaveLength(1);
  });

  it('cannot enable checks before the domain is confirmed', () => {
    const wrapper = mount(ScenarioPanel, { props: { store: makeStore(), scenario: scenario({ enabled: false }), canManage: true }, global });
    const enable = wrapper.findAll('button').find((button) => button.text() === 'Включить проверки')!;

    expect(enable.attributes('disabled')).toBeDefined();
  });

  it('lists what is checked and what is not, and that nothing is bought', () => {
    const wrapper = mount(ScenarioPanel, { props: { store: verifiedStore(), scenario: scenario(), canManage: false }, global });

    expect(wrapper.text()).toContain('форма оплаты');
    expect(wrapper.text()).toContain('товары с вариантами');
    expect(wrapper.text()).toContain('не создаёт заказ');
    expect(wrapper.text()).toContain('каждые 15 минут');
    expect(wrapper.findAll('button')).toHaveLength(0);
  });

  it('toggles with the scenario version', async () => {
    const current = scenario();
    mocked.updateScenario.mockResolvedValue({ ...current, enabled: false, version: 3 });
    const wrapper = mount(ScenarioPanel, { props: { store: verifiedStore(), scenario: current, canManage: true }, global });

    await wrapper.findAll('button').find((button) => button.text() === 'Выключить проверки')!.trigger('click');
    await flushPromises();

    expect(mocked.updateScenario).toHaveBeenCalledWith(current, { enabled: false });
  });
});

describe('ScenarioPanel for Shopware (ADR 0055)', () => {
  const shopware = (): Store => ({ ...verifiedStore(), platform: 'shopware' });
  const accessLabels = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('[data-field="checkout-access"] label').map((label) => label.text());

  it('asks how to reach payment methods only for Shopware', () => {
    const woo = mount(ScenarioPanel, { props: { store: verifiedStore(), scenario: null, canManage: true }, global });
    expect(woo.find('[data-field="checkout-access"]').exists()).toBe(false);

    const wrapper = mount(ScenarioPanel, { props: { store: shopware(), scenario: null, canManage: true }, global });
    const labels = accessLabels(wrapper);
    expect(labels).toHaveLength(3);
    expect(labels[0]).toContain('Остановиться перед входом');
    expect(labels[1]).toContain('Тестовый покупатель');
    expect(labels[2]).toContain('Гость');
    expect((wrapper.get('input[value="stop_before_sign_in"]').element as HTMLInputElement).checked).toBe(true);
  });

  it('warns how many guest customers a day the guest way creates', async () => {
    const wrapper = mount(ScenarioPanel, { props: { store: shopware(), scenario: null, canManage: true }, global });

    await wrapper.get('input[value="guest"]').setValue(true);
    expect(wrapper.get('[data-note="guests-per-day"]').text()).toContain('около 96 в сутки');

    await wrapper.get('#scenario-interval').setValue('3600');
    expect(wrapper.get('[data-note="guests-per-day"]').text()).toContain('около 24 в сутки');
  });

  it('sends the test customer account and needs it before saving', async () => {
    mocked.createScenario.mockResolvedValue(scenario({ platform: 'shopware', checkout_access: 'test_customer', sign_in_configured: true }));
    const wrapper = mount(ScenarioPanel, { props: { store: shopware(), scenario: null, canManage: true }, global });
    const submit = () => wrapper.get('button[type="submit"]');

    await wrapper.get('#scenario-product').setValue('https://kaffee-lindner.example/detail/1');
    await wrapper.get('input[value="test_customer"]').setValue(true);
    expect(submit().attributes('disabled')).toBeDefined();
    expect(wrapper.get('#scenario-sign-in-password').attributes('type')).toBe('password');

    await wrapper.get('#scenario-sign-in-email').setValue('check@kaffee-lindner.example');
    await wrapper.get('#scenario-sign-in-password').setValue('secret-pass');
    expect(submit().attributes('disabled')).toBeUndefined();
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(mocked.createScenario).toHaveBeenCalledWith('s-1', expect.objectContaining({
      checkout_access: 'test_customer',
      sign_in: { email: 'check@kaffee-lindner.example', password: 'secret-pass' },
    }));
  });

  it('keeps a saved account without asking for the password again', async () => {
    const current = scenario({ platform: 'shopware', checkout_access: 'test_customer', sign_in_configured: true, adapter_version: 'shopware-payment-form@1' });
    mocked.updateScenario.mockResolvedValue({ ...current, version: 3, interval_seconds: 3600 });
    const wrapper = mount(ScenarioPanel, { props: { store: shopware(), scenario: current, canManage: true }, global });

    expect(wrapper.get('[data-field="checkout-access"]').text()).toBe('Тестовый покупатель · учётная запись сохранена');
    expect(wrapper.text()).not.toContain('secret');

    await wrapper.findAll('button').find((button) => button.text() === 'Изменить')!.trigger('click');
    expect(wrapper.text()).toContain('Сохранён. Оставьте поля пустыми');
    await wrapper.get('#scenario-interval').setValue('3600');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    const sent = mocked.updateScenario.mock.calls[0]![1];
    expect(sent.checkout_access).toBe('test_customer');
    expect(sent).not.toHaveProperty('sign_in');
  });
});

describe('RunsList', () => {
  it('starts a manual run only when ready and shows run results', async () => {
    mocked.listRuns.mockResolvedValue({
      data: [{ id: 'run-1', store_id: 's-1', scenario_id: 'sc-1', scenario_version: 2, trigger: 'scheduled', status: 'failed', error_code: 'site_failure', scheduled_at: '2026-10-10T10:00:00Z', started_at: null, finished_at: '2026-10-10T10:02:00Z' }],
      next_cursor: null,
    });
    mocked.startRun.mockResolvedValue({} as never);

    const notReady = mount(RunsList, { props: { storeId: 's-1', scenario: scenario(), canRun: true, ready: false }, global });
    await flushPromises();
    expect(notReady.findAll('button').find((button) => button.text() === 'Запустить сейчас')!.attributes('disabled')).toBeDefined();
    expect(notReady.get('[data-run-id="run-1"] [data-tone]').attributes('data-tone')).toBe('crit');
    expect(notReady.text()).toContain('Сайт не дал дойти до формы оплаты.');
    notReady.unmount();

    const ready = mount(RunsList, { props: { storeId: 's-1', scenario: scenario(), canRun: true, ready: true }, global });
    await flushPromises();
    await ready.findAll('button').find((button) => button.text() === 'Запустить сейчас')!.trigger('click');
    await flushPromises();
    expect(mocked.startRun).toHaveBeenCalledWith('s-1', 'sc-1');
    ready.unmount();
  });
});
