import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api/incidents';
import type { IncidentDetail, IncidentExplanation as Explanation } from '@/api/types';
import IncidentActions from '@/components/incidents/IncidentActions.vue';
import IncidentEvidence from '@/components/incidents/IncidentEvidence.vue';
import IncidentExplanation from '@/components/incidents/IncidentExplanation.vue';
import IncidentListItem from '@/components/incidents/IncidentListItem.vue';
import IncidentTimeline from '@/components/incidents/IncidentTimeline.vue';
import { formatMinor } from '@bw/ui';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';
import { localization, setLocale } from '@/i18n';

vi.mock('@/api/incidents', () => ({
  acknowledgeIncident: vi.fn(),
  resolveIncident: vi.fn(),
  commentIncident: vi.fn(),
  snoozeIncident: vi.fn(),
  revokeSuppression: vi.fn(),
  recheckMoney: vi.fn(),
  getCheckRun: vi.fn(),
  startManualCheck: vi.fn(),
  artifactLink: vi.fn(),
}));

const mocked = vi.mocked(api);
const global = { plugins: [localization], stubs: { RouterLink: RouterLinkStub } };

function incident(overrides: Partial<IncidentDetail> = {}): IncidentDetail {
  return {
    id: 'inc-1',
    store_id: 's-1',
    family: 'money',
    component: 'capture',
    state: 'open',
    severity: 'warning',
    title_code: 'MONEY_CAPTURE_MISSING',
    currency: 'EUR',
    mode: 'live',
    currency_exponent: 2,
    verified_discrepancy_minor: '18400',
    first_seen_at: '2026-10-09T10:00:00Z',
    last_seen_at: '2026-10-09T11:00:00Z',
    last_good_at: null,
    first_bad_at: '2026-10-09T10:00:00Z',
    acknowledged_by: null,
    acknowledged_at: null,
    resolved_at: null,
    resolution_reason: null,
    revision: 3,
    created_at: '2026-10-09T10:00:00Z',
    updated_at: '2026-10-09T11:00:00Z',
    signals: [],
    findings: [
      {
        id: 'f-1',
        run_id: 'r-1',
        order_id: 'o-1',
        payment_id: null,
        rule_code: 'MONEY_CAPTURE_MISSING',
        status: 'mismatch',
        reason_code: 'capture_missing_grace_expired',
        currency: 'EUR',
        mode: 'live',
        currency_exponent: 2,
        expected_minor: '18400',
        actual_minor: '0',
        difference_minor: null,
        evaluated_at: '2026-10-09T10:00:00Z',
        order_display_number: '#15238',
      },
    ],
    activity: [],
    active_suppression: null,
    ...overrides,
  };
}

beforeEach(() => {
  vi.clearAllMocks();
  setLocale('ru');
});

describe('formatMinor', () => {
  it('formats minor-unit strings exactly, also beyond the safe integer range', () => {
    expect(formatMinor('18400', 'EUR', 2, 'de')).toBe('184,00 €');
    expect(formatMinor('900719925474099312', 'EUR', 2, 'en')).toBe('€9,007,199,254,740,993.12');
    expect(formatMinor('-5', 'EUR', 2, 'en')).toBe('-€0.05');
    expect(formatMinor('1500', 'JPY', 0, 'en')).toBe('¥1,500');
    expect(formatMinor('12.5', 'EUR', 2, 'en')).toBeNull();
  });
});

describe('incident title codes', () => {
  it('every title code the backend can raise has a title and advice', () => {
    const root = resolve(__dirname, '../../../backend/app');
    const files: string[] = [];
    const walk = (dir: string): void => {
      for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) walk(path);
        else if (name.endsWith('.php')) files.push(path);
      }
    };
    walk(root);
    const codes = new Set(files.flatMap((file) => [...readFileSync(file, 'utf8').matchAll(/'((?:MONEY|CHECKOUT|INTEGRATION)_[A-Z_]+)'/g)].map((match) => match[1]!)));
    const titles = en.incident.title as Record<string, string>;
    const advice = en.incident.advice as Record<string, string>;

    expect(codes.size).toBeGreaterThan(10);

    for (const code of codes) {
      expect(titles[code], `title for ${code}`).toBeTruthy();
      expect(advice[code], `advice for ${code}`).toBeTruthy();
    }
  });
});

function explanation(overrides: Partial<Explanation> = {}): Explanation {
  return {
    scenario: 'capture_unconfirmed',
    variant: 'with_reference',
    params: { order: '#1042', provider: 'stripe', reference: 'pi_123', amount: { minor: '8900', currency: 'EUR', exponent: 2 } },
    evidence: [
      { item: 'store_order', value: 'order', params: { order: '#1042', amount: { minor: '8900', currency: 'EUR', exponent: 2 } } },
      { item: 'payment_reference', value: 'from_order', params: { reference: 'pi_123' } },
      { item: 'successful_capture', value: 'not_confirmed', params: {} },
      { item: 'data_completeness', value: 'full', params: {} },
    ],
    causes: ['capture_pending'],
    steps: ['open_payment_by_reference', 'check_captured_not_authorized', 'compare_reference', 'recheck'],
    warning: 'no_recharge',
    auto_close: 'capture_linked_or_unmarked',
    observation: { state: 'fresh', reason: null, checked_at: '2026-10-22T08:00:00Z' },
    status: 'detected',
    outcome: null,
    technical: { rule_code: 'MONEY_CAPTURE_MISSING', payment_intent: 'pi_123' },
    ...overrides,
  };
}

describe('IncidentExplanation', () => {
  beforeEach(() => setLocale('ru'));

  it('says what is established, what it rests on and where to start, without calling it a loss', () => {
    const wrapper = mount(IncidentExplanation, { props: { explanation: explanation() }, global });

    expect(wrapper.get('[data-headline]').text()).toBe('Заказ #1042 отмечен оплаченным, но списание в Stripe пока не подтверждено');
    expect(wrapper.get('[data-fact]').text()).toContain(formatMinor('8900', 'EUR', 2, 'ru'));
    expect(wrapper.get('[data-fact]').text()).toContain('расхождение, а не потеря денег');
    expect(wrapper.get('[data-evidence-item="payment_reference"]').text()).toContain('получена из заказа: pi_123');
    expect(wrapper.get('[data-problem-status]').text()).toContain('Обнаружена, требует проверки');
    expect(wrapper.get('[data-observation]').text()).toContain('Свежие и полные');
    expect(wrapper.findAll('[data-cause]').map((item) => item.attributes('data-cause'))).toEqual(['capture_pending']);
    expect(wrapper.get('[data-step="open_payment_by_reference"]').text()).toBe('Откройте платёж в Stripe по идентификатору pi_123.');
    expect(wrapper.get('[data-warning]').text()).toContain('Не списывайте деньги с покупателя повторно');
    expect(wrapper.get('[data-auto-close]').text()).toContain('закроется сам');
    expect(wrapper.get('[data-technical]').text()).toContain('pi_123');
  });

  it('words a missing amount and an unknown provider instead of leaving gaps', () => {
    const wrapper = mount(IncidentExplanation, {
      props: {
        explanation: explanation({
          variant: 'without_reference',
          params: { order: '#1042' },
          evidence: [{ item: 'store_order', value: 'order_only', params: { order: '#1042' } }, { item: 'payment_reference', value: 'missing', params: {} }],
          steps: ['check_payment_module_data'],
        }),
      },
      global,
    });

    expect(wrapper.get('[data-headline]').text()).toBe('Заказ #1042 отмечен оплаченным, но списание у платёжного провайдера пока не подтверждено');
    expect(wrapper.get('[data-fact]').text()).toContain('В магазине заказ отмечен оплаченным.');
    expect(wrapper.get('[data-evidence-item="store_order"]').text()).not.toContain('сумма');
    expect(wrapper.get('[data-evidence-item="payment_reference"]').text()).toContain('в заказе отсутствует');
  });

  it('keeps the state of the problem apart from the state of the data and after a manual close', () => {
    const unverifiable = mount(IncidentExplanation, {
      props: { explanation: explanation({ status: 'unverifiable', observation: { state: 'unavailable', reason: 'provider_access_lost', checked_at: null }, outcome: { code: 'not_rechecked', at: '2026-10-22T09:00:00Z', reason: null } }) },
      global,
    });
    expect(unverifiable.get('[data-problem-status]').text()).toContain('сейчас перепроверить нельзя');
    expect(unverifiable.get('[data-observation]').text()).toContain('доступ в Stripe отозван');
    expect(unverifiable.get('[data-outcome]').text()).toContain('не смогла перепроверить');

    const closed = mount(IncidentExplanation, { props: { explanation: explanation({ status: 'closed_by_user', outcome: { code: 'closed_by_user', at: '2026-10-22T09:00:00Z', reason: null } }) }, global });
    expect(closed.get('[data-outcome]').text()).toContain('Это не подтверждает, что расхождение устранено.');
    expect(closed.find('[data-auto-close]').exists()).toBe(false);
  });

  it('names the step a browser check stopped at and links the run', () => {
    const wrapper = mount(IncidentExplanation, {
      props: {
        explanation: explanation({
          scenario: 'browser_check_stopped',
          variant: 'step_known',
          params: { step: 'cart', error: 'site_failure', check_run_id: 'run-1' },
          evidence: [{ item: 'stopped_step', value: 'step', params: { step: 'cart' } }],
          causes: ['step_failed_on_site'],
          steps: ['open_check_run', 'repeat_path_manually'],
          warning: 'browser_check_no_payment',
          observation: null,
        }),
      },
      global,
    });

    expect(wrapper.get('[data-headline]').text()).toBe('Проверка оформления заказа остановилась на шаге «корзина»');
    expect(wrapper.get('[data-evidence-item="stopped_step"]').text()).toContain('корзина');
    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({ name: 'check-run', params: { id: 'run-1' } });
    expect(wrapper.find('[data-observation]').exists()).toBe(false);
  });

  it('every cause, step, warning and closing condition the backend can return has a text in all languages', () => {
    const source = readFileSync(resolve(__dirname, '../../../backend/app/Support/Incidents/IncidentExplainer.php'), 'utf8');
    const block = source.slice(source.indexOf('TEXT_CODES = ['), source.indexOf('];', source.indexOf('TEXT_CODES = [')));
    const groups = Object.fromEntries([...block.matchAll(/'([a-z_]+)' => \[([^\]]*)\]/g)].map((match) => [match[1]!, [...match[2]!.matchAll(/'([a-z_]+)'/g)].map((code) => code[1]!)]));
    const sources = { ru, en, de } as unknown as Record<string, { incident: { explain: Record<string, Record<string, string>> } }>;

    expect(Object.keys(groups)).toEqual(['cause', 'step', 'warning', 'auto_close']);
    for (const [group, codes] of Object.entries(groups)) {
      expect(codes.length, group).toBeGreaterThan(0);
      for (const code of codes) {
        for (const [locale, messages] of Object.entries(sources)) {
          expect(messages.incident.explain[group]?.[code], `${locale} ${group}.${code}`).toBeTruthy();
        }
      }
    }
  });
});

describe('IncidentListItem', () => {
  it('names the store order in the title of a money incident', () => {
    const money = mount(IncidentListItem, { props: { incident: incident({ title_code: 'MONEY_CAPTURE_NOT_RECORDED', order_display_number: '10004' }), storeName: null }, global });
    const checkout = mount(IncidentListItem, { props: { incident: incident({ family: 'checkout_payment', title_code: 'CHECKOUT_PAYMENTS_FAILING', order_display_number: '10004' }), storeName: null }, global });

    expect(money.text()).toContain('Деньги списаны, но заказ не отмечен оплаченным — заказ 10004');
    expect(checkout.text()).not.toContain('заказ 10004');
  });
});

describe('IncidentActions', () => {
  it('acknowledges with the revision the user saw', async () => {
    mocked.acknowledgeIncident.mockResolvedValue({} as never);
    const value = incident();
    const wrapper = mount(IncidentActions, { props: { incident: value, canHandle: true, canManage: true }, global });

    await wrapper.findAll('button').find((button) => button.text() === 'Подтвердить')!.trigger('click');
    await flushPromises();

    expect(mocked.acknowledgeIncident).toHaveBeenCalledWith(value);
    expect(wrapper.emitted('changed')).toHaveLength(1);
  });

  it('requires a reason before resolving', async () => {
    mocked.resolveIncident.mockResolvedValue({} as never);
    const value = incident();
    const wrapper = mount(IncidentActions, { props: { incident: value, canHandle: true, canManage: false }, global });

    await wrapper.findAll('button').find((button) => button.text() === 'Закрыть')!.trigger('click');
    const submit = wrapper.findAll('button').find((button) => button.text() === 'Закрыть инцидент')!;
    expect(submit.attributes('disabled')).toBeDefined();

    await wrapper.get('#resolve-reason').setValue('Payment found under another reference');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(mocked.resolveIncident).toHaveBeenCalledWith(value, 'Payment found under another reference');
    expect(wrapper.text()).not.toContain('Заглушить уведомления');
  });

  it('rechecks the orders behind a money incident', async () => {
    mocked.recheckMoney.mockResolvedValue();
    const wrapper = mount(IncidentActions, { props: { incident: incident(), canHandle: true, canManage: false }, global });

    await wrapper.findAll('button').find((button) => button.text() === 'Пересверить сейчас')!.trigger('click');
    await flushPromises();

    expect(mocked.recheckMoney).toHaveBeenCalledWith('s-1', ['o-1']);
    expect(wrapper.text()).toContain('Сверка выполнена');
  });

  it('starts a manual check from the scenario of the failed run', async () => {
    mocked.getCheckRun.mockResolvedValue({ id: 'run-1', store_id: 's-1', scenario_id: 'sc-1', status: 'failed', error_code: 'site_failure', finished_at: null, attempts: [] });
    mocked.startManualCheck.mockResolvedValue({} as never);
    const value = incident({
      family: 'checkout',
      title_code: 'CHECKOUT_FLOW_FAILED',
      findings: [],
      signals: [{ id: 'sig-1', signal_type: 'browser_check', family: 'checkout', component: 'payment_form', severity: 'warning', confidence: 'observed', currency: null, finding_id: null, evidence: { check_run_id: 'run-1' }, detected_at: '2026-10-09T10:00:00Z' }],
    });
    const wrapper = mount(IncidentActions, { props: { incident: value, canHandle: true, canManage: true }, global });

    await wrapper.findAll('button').find((button) => button.text() === 'Запустить проверку')!.trigger('click');
    await flushPromises();

    expect(mocked.startManualCheck).toHaveBeenCalledWith('s-1', 'sc-1');
  });

  it('shows an active snooze and nothing to act on for viewers', () => {
    const wrapper = mount(IncidentActions, {
      props: {
        incident: incident({ active_suppression: { id: 'sup-1', incident_id: 'inc-1', reason: 'known', created_by: null, starts_at: '2026-10-09T10:00:00Z', ends_at: '2026-10-10T10:00:00Z', revoked_at: null, created_at: '2026-10-09T10:00:00Z' } }),
        canHandle: false,
        canManage: false,
      },
      global,
    });

    expect(wrapper.get('[data-suppression]').text()).toContain('Уведомления заглушены до');
    expect(wrapper.findAll('button')).toHaveLength(0);
    expect(wrapper.text()).toContain('только для просмотра');
  });
});

describe('IncidentEvidence', () => {
  it('shows finding amounts and says unknown instead of zero', () => {
    setLocale('de');
    const wrapper = mount(IncidentEvidence, { props: { incident: incident() }, global });
    const cells = wrapper.findAll('[data-finding-id="f-1"] td').map((cell) => cell.text());

    expect(cells[1]).toBe('Abweichung');
    expect(cells[2]).toBe('#15238');
    expect(cells[3]).toBe('184,00 €');
    expect(cells[4]).toBe('0,00 €');
    expect(cells[5]).toBe('unbekannt');
  });

  it('explains payment attempt evidence in plain words', () => {
    const wrapper = mount(IncidentEvidence, {
      props: {
        incident: incident({
          family: 'checkout_payment',
          findings: [],
          signals: [{ id: 'sig-2', signal_type: 'payment_attempts', family: 'checkout_payment', component: 'stripe', severity: 'warning', confidence: 'observed', currency: null, finding_id: null, evidence: { payment_method: 'stripe', failure_streak: 4, threshold: 3, last_success_at: null }, detected_at: '2026-10-09T10:00:00Z' }],
        }),
      },
      global,
    });

    expect(wrapper.text()).toContain('Способ оплаты «stripe»: 4 неудачных попыток подряд (порог 3).');
    expect(wrapper.text()).toContain('Последняя успешная оплата: ещё не было.');
  });
});

describe('IncidentTimeline', () => {
  it('renders comments as plain text and posts new ones', async () => {
    mocked.commentIncident.mockResolvedValue({} as never);
    const wrapper = mount(IncidentTimeline, {
      props: {
        incidentId: 'inc-1',
        canComment: true,
        activity: [
          { id: 'a-1', incident_id: 'inc-1', kind: 'created', actor_id: null, incident_revision: 1, data: {}, created_at: '2026-10-09T10:00:00Z' },
          { id: 'a-2', incident_id: 'inc-1', kind: 'comment', actor_id: 'u-9', incident_revision: 1, data: { text: '<b>checked</b>' }, created_at: '2026-10-09T10:05:00Z' },
          { id: 'a-3', incident_id: 'inc-1', kind: 'resolved', actor_id: null, incident_revision: 2, data: { reason: 'auto_resolved_fresh_reconciliation_ok' }, created_at: '2026-10-09T10:10:00Z' },
        ],
      },
      global,
    });

    expect(wrapper.find('b').exists()).toBe(false);
    expect(wrapper.text()).toContain('<b>checked</b>');
    expect(wrapper.text()).toContain('свежая сверка не нашла расхождения');
    expect(wrapper.findAll('[data-activity-kind]')[0]!.attributes('data-activity-kind')).toBe('resolved');

    await wrapper.get('#incident-comment').setValue('Looking into it');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(mocked.commentIncident).toHaveBeenCalledWith('inc-1', 'Looking into it');
    expect(wrapper.emitted('commented')).toHaveLength(1);
  });

  it('shows what Business Watchdog support did on the incident and why', () => {
    const wrapper = mount(IncidentTimeline, {
      props: {
        incidentId: 'inc-1',
        canComment: false,
        activity: [{ id: 'a-1', incident_id: 'inc-1', kind: 'resolved', actor_id: null, incident_revision: 2, data: { reason: 'Fixed together by phone', by: 'platform' }, created_at: '2026-10-09T10:10:00Z' }],
      },
      global,
    });

    expect(wrapper.text()).toContain('поддержка Business Watchdog');
    expect(wrapper.text()).toContain('Fixed together by phone');
    expect(wrapper.text()).not.toContain('система');
  });
});
