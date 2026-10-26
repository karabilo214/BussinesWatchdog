import { onBeforeUnmount, onMounted, ref } from 'vue';
import { getOverview } from '@/api/overview';
import type { CheckRunSummary, Overview } from '@/api/types';

export type BadgeLevel = 'crit' | 'warn' | 'note';
export type NavSection = 'overview' | 'incidents' | 'reconciliation' | 'checks';
export interface NavBadge {
  count: number;
  level: BadgeLevel;
  parts: { key: string; n: number }[];
}

const REFRESH_MS = 60_000;
const FAILED: CheckRunSummary['status'][] = ['failed'];
const UNSURE: CheckRunSummary['status'][] = ['blocked', 'inconclusive'];

/** Counts of what needs attention per menu section, from the overview the server already scopes to the member. */
export function navBadges(overview: Overview): Partial<Record<NavSection, NavBadge>> {
  const badges: Partial<Record<NavSection, NavBadge>> = {};
  const severity = overview.incidents?.by_severity;

  if (overview.incidents && severity && overview.incidents.active > 0) {
    badges.incidents = {
      count: overview.incidents.active,
      level: severity.critical > 0 ? 'crit' : severity.warning > 0 ? 'warn' : 'note',
      parts: [
        { key: 'nav_badge.critical', n: severity.critical },
        { key: 'nav_badge.warning', n: severity.warning },
        { key: 'nav_badge.info', n: severity.info },
      ].filter((part) => part.n > 0),
    };
  }

  const discrepancies = (overview.discrepancies ?? []).reduce((sum, row) => sum + row.incident_count, 0) + (overview.unknown_amount_incidents ?? 0);
  if (discrepancies > 0) {
    badges.reconciliation = { count: discrepancies, level: 'warn', parts: [{ key: 'nav_badge.discrepancies', n: discrepancies }] };
  }

  const latest = new Map<string, CheckRunSummary>();
  for (const run of overview.recent_checks) {
    const known = latest.get(run.scenario_id);
    if (known === undefined || (run.finished_at ?? '') > (known.finished_at ?? '')) latest.set(run.scenario_id, run);
  }
  const failed = [...latest.values()].filter((run) => FAILED.includes(run.status)).length;
  const unsure = [...latest.values()].filter((run) => UNSURE.includes(run.status)).length;
  if (failed + unsure > 0) {
    badges.checks = {
      count: failed + unsure,
      level: failed > 0 ? 'crit' : 'warn',
      parts: [{ key: 'nav_badge.checks_failed', n: failed }, { key: 'nav_badge.checks_unsure', n: unsure }].filter((part) => part.n > 0),
    };
  }

  const paused = overview.capacity_paused_stores ?? 0;
  if (paused > 0) {
    badges.overview = { count: paused, level: 'warn', parts: [{ key: 'nav_badge.paused_stores', n: paused }] };
  }

  return badges;
}

const badges = ref<Partial<Record<NavSection, NavBadge>>>({});
let loadedAt = 0;
let pending: Promise<void> | null = null;

async function refresh(force = false): Promise<void> {
  if (pending !== null || (!force && Date.now() - loadedAt < REFRESH_MS)) return pending ?? undefined;
  pending = getOverview()
    .then((overview) => {
      badges.value = navBadges(overview);
      loadedAt = Date.now();
    })
    .catch(() => undefined)
    .finally(() => {
      pending = null;
    });

  return pending;
}

export function useNavBadges() {
  let timer: ReturnType<typeof setInterval> | null = null;

  onMounted(() => {
    void refresh();
    timer = setInterval(() => {
      if (typeof document === 'undefined' || !document.hidden) void refresh(true);
    }, REFRESH_MS);
  });

  onBeforeUnmount(() => {
    if (timer !== null) clearInterval(timer);
  });

  return { badges };
}
