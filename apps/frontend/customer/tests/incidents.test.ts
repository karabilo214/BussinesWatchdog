import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { flushPromises, mount, RouterLinkStub } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as api from '@/api/incidents';
import type { IncidentDetail } from '@/api/types';
import IncidentActions from '@/components/incidents/IncidentActions.vue';
import IncidentEvidence from '@/components/incidents/IncidentEvidence.vue';
import IncidentTimeline from '@/components/incidents/IncidentTimeline.vue';
import { formatMinor } from '@/composables/money';
import en from '@/i18n/locales/en.json';
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

    expect(cells[1]).toBe('#15238');
    expect(cells[2]).toBe('184,00 €');
    expect(cells[3]).toBe('0,00 €');
    expect(cells[4]).toBe('unbekannt');
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
});
