import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';
import { fetchOwnerAttention, type OwnerAttention } from '@/api';

export type OwnerBadgeLevel = 'crit' | 'warn';
export interface OwnerBadge {
  text: string;
  level: OwnerBadgeLevel;
  hint: { key: string; n: number };
}

const REFRESH_MS = 60_000;

/** What needs the staff member's attention per menu section; sections without a value get no badge. */
export function ownerBadges(attention: OwnerAttention): Record<string, OwnerBadge> {
  const badges: Record<string, OwnerBadge> = {};
  const count = (n: number): string => (n > 99 ? '99+' : String(n));

  if ((attention.contacts_new ?? 0) > 0) {
    badges.contacts = { text: count(attention.contacts_new!), level: 'warn', hint: { key: 'nav_badge.contacts_new', n: attention.contacts_new! } };
  }

  if (attention.service_state === 'crit' || attention.service_state === 'warn') {
    badges.service = { text: '!', level: attention.service_state, hint: { key: `nav_badge.service_${attention.service_state}`, n: 1 } };
  }

  if ((attention.refunds_requested ?? 0) > 0) {
    badges.payments = { text: count(attention.refunds_requested!), level: 'warn', hint: { key: 'nav_badge.refunds_requested', n: attention.refunds_requested! } };
  }

  if ((attention.subscriptions_past_due ?? 0) > 0) {
    badges.subscriptions = { text: count(attention.subscriptions_past_due!), level: 'warn', hint: { key: 'nav_badge.subscriptions_past_due', n: attention.subscriptions_past_due! } };
  }

  if ((attention.support_requests_pending ?? 0) > 0) {
    badges.tenants = { text: count(attention.support_requests_pending!), level: 'warn', hint: { key: 'nav_badge.support_requests_pending', n: attention.support_requests_pending! } };
  }

  return badges;
}

export function useOwnerBadges(sections: Ref<string[]>) {
  const badges = ref<Record<string, OwnerBadge>>({});
  let timer: ReturnType<typeof setInterval> | null = null;

  async function refresh(): Promise<void> {
    if (sections.value.length === 0) { badges.value = {}; return; }
    try {
      const attention = await fetchOwnerAttention();
      const allowed = new Set(sections.value);
      badges.value = Object.fromEntries(Object.entries(ownerBadges(attention)).filter(([section]) => allowed.has(section)));
    } catch {
      badges.value = {};
    }
  }

  onMounted(() => {
    void refresh();
    timer = setInterval(() => {
      if (!document.hidden) void refresh();
    }, REFRESH_MS);
  });
  watch(() => sections.value.join(','), () => void refresh());
  onBeforeUnmount(() => {
    if (timer !== null) clearInterval(timer);
  });

  return { badges };
}
