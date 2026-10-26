import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { getAttention, getOverview } from '@/api/overview';
import type { Attention, AttentionCode, AttentionSection, CheckRunSummary, Overview } from '@/api/types';

export type BadgeLevel = 'crit' | 'warn' | 'note';
export type NavSection = 'overview' | 'incidents' | 'reconciliation' | 'checks' | 'notifications' | 'settings';
export interface NavBadge {
  count: number;
  level: BadgeLevel;
  parts: { key: string; n: number; code?: AttentionCode; level?: BadgeLevel }[];
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

function fromAttention(section: AttentionSection | null): NavBadge | undefined {
  if (section === null) return undefined;

  return { count: section.count, level: section.level, parts: section.parts.map((part) => ({ key: `nav_badge.${part.code}`, n: part.n, code: part.code, level: part.level })) };
}

/** Notifications and settings come from the server already counted and levelled for this member. */
export function attentionBadges(attention: Attention): Partial<Record<NavSection, NavBadge>> {
  const badges: Partial<Record<NavSection, NavBadge>> = {};
  const notifications = fromAttention(attention.notifications);
  const settings = fromAttention(attention.settings);

  if (notifications) badges.notifications = notifications;
  if (settings) badges.settings = settings;

  return badges;
}

const fromOverview = ref<Partial<Record<NavSection, NavBadge>>>({});
const fromServer = ref<Partial<Record<NavSection, NavBadge>>>({});
const badges = computed(() => ({ ...fromOverview.value, ...fromServer.value }));
let loadedAt = 0;
let pending: Promise<void> | null = null;

async function refresh(force = false): Promise<void> {
  if (pending !== null || (!force && Date.now() - loadedAt < REFRESH_MS)) return pending ?? undefined;
  pending = Promise.allSettled([getOverview(), getAttention()])
    .then(([overview, attention]) => {
      if (overview.status === 'fulfilled') fromOverview.value = navBadges(overview.value);
      if (attention.status === 'fulfilled') fromServer.value = attentionBadges(attention.value);
      loadedAt = Date.now();
    })
    .catch(() => undefined)
    .finally(() => {
      pending = null;
    });

  return pending;
}

/** The current counters without starting another refresh timer (the layout owns it). */
export function useNavBadgeState() {
  return { badges, refresh };
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
