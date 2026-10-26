import { describe, expect, it } from 'vitest';
import type { Overview } from '@/api/types';
import { attentionBadges, navBadges } from '@/composables/useNavBadges';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';

function overview(overrides: Partial<Overview> = {}): Overview {
  return {
    incidents: { active: 0, by_severity: { info: 0, warning: 0, critical: 0 }, latest: [] },
    discrepancies: [],
    unknown_amount_incidents: 0,
    capacity_paused_stores: 0,
    recent_checks: [],
    ...overrides,
  };
}

const run = (scenario: string, status: string, finished: string) => ({ id: `${scenario}-${finished}`, scenario_id: scenario, status, finished_at: finished }) as never;

describe('menu badges', () => {
  it('shows nothing when nothing needs attention', () => {
    expect(navBadges(overview())).toEqual({});
  });

  it('colours incidents by the most severe one and explains the count', () => {
    const badges = navBadges(overview({ incidents: { active: 5, by_severity: { info: 1, warning: 2, critical: 2 }, latest: [] } }));
    expect(badges.incidents).toMatchObject({ count: 5, level: 'crit' });
    expect(badges.incidents?.parts).toEqual([{ key: 'nav_badge.critical', n: 2 }, { key: 'nav_badge.warning', n: 2 }, { key: 'nav_badge.info', n: 1 }]);
    expect(navBadges(overview({ incidents: { active: 1, by_severity: { info: 0, warning: 1, critical: 0 }, latest: [] } })).incidents?.level).toBe('warn');
    expect(navBadges(overview({ incidents: { active: 1, by_severity: { info: 1, warning: 0, critical: 0 }, latest: [] } })).incidents?.level).toBe('note');
  });

  it('counts open discrepancies including those of unknown amount, latest failed checks per scenario and paused stores', () => {
    const badges = navBadges(overview({
      discrepancies: [{ mode: 'live', currency: 'EUR', currency_exponent: 2, component: 'capture', total_minor: '100', incident_count: 2 }],
      unknown_amount_incidents: 1,
      recent_checks: [run('a', 'failed', '2026-10-25T09:00:00Z'), run('a', 'passed', '2026-10-25T10:00:00Z'), run('b', 'failed', '2026-10-25T10:00:00Z'), run('c', 'blocked', '2026-10-25T10:00:00Z')],
      capacity_paused_stores: 1,
    }));
    expect(badges.reconciliation).toMatchObject({ count: 3, level: 'warn' });
    expect(badges.checks).toMatchObject({ count: 2, level: 'crit' });
    expect(badges.overview).toMatchObject({ count: 1, level: 'warn' });
  });

  it('takes notification and settings counters with their level from the server and omits empty sections', () => {
    expect(attentionBadges({ notifications: null, settings: null })).toEqual({});

    const badges = attentionBadges({
      notifications: { count: 2, level: 'crit', parts: [{ code: 'channels_failing', n: 1, level: 'crit' }, { code: 'channels_unverified', n: 1, level: 'warn' }] },
      settings: { count: 1, level: 'warn', parts: [{ code: 'owner_mfa_off', n: 1, level: 'warn' }] },
    });
    expect(badges.notifications).toEqual({ count: 2, level: 'crit', parts: [{ key: 'nav_badge.channels_failing', n: 1, code: 'channels_failing', level: 'crit' }, { key: 'nav_badge.channels_unverified', n: 1, code: 'channels_unverified', level: 'warn' }] });
    expect(badges.settings).toMatchObject({ count: 1, level: 'warn' });
  });

  it('has the explanation texts in ru, en and de', () => {
    const keys = ['critical', 'warning', 'info', 'discrepancies', 'checks_failed', 'checks_unsure', 'paused_stores', 'channels_failing', 'channels_unverified', 'subscription_past_due', 'support_requests_pending', 'owner_mfa_off'];
    for (const locale of [ru, en, de]) {
      expect(keys.filter((key) => !(locale.nav_badge as Record<string, string>)[key])).toEqual([]);
    }
  });
});
