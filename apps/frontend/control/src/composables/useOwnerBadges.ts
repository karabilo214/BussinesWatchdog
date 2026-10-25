import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';
import { fetchContactRequests, fetchRefunds, fetchServiceHealth, type HealthState } from '@/api';

export type OwnerBadgeLevel = 'crit' | 'warn';
export interface OwnerBadge {
  text: string;
  level: OwnerBadgeLevel;
  hint: { key: string; n: number };
}

export interface OwnerAttention {
  contactsNew?: number;
  health?: HealthState;
  refundsRequested?: number;
}

const REFRESH_MS = 60_000;

/** What needs the staff member's attention per menu section; sections without a value get no badge. */
export function ownerBadges(attention: OwnerAttention): Record<string, OwnerBadge> {
  const badges: Record<string, OwnerBadge> = {};
  const count = (n: number): string => (n > 99 ? '99+' : String(n));

  if ((attention.contactsNew ?? 0) > 0) {
    badges.contacts = { text: count(attention.contactsNew!), level: 'warn', hint: { key: 'nav_badge.contacts_new', n: attention.contactsNew! } };
  }

  if (attention.health === 'crit' || attention.health === 'warn') {
    badges.service = { text: '!', level: attention.health, hint: { key: `nav_badge.service_${attention.health}`, n: 1 } };
  }

  if ((attention.refundsRequested ?? 0) > 0) {
    badges.payments = { text: count(attention.refundsRequested!), level: 'warn', hint: { key: 'nav_badge.refunds_requested', n: attention.refundsRequested! } };
  }

  return badges;
}

async function settle<T>(enabled: boolean, load: () => Promise<T>): Promise<T | undefined> {
  if (!enabled) return undefined;
  try {
    return await load();
  } catch {
    return undefined;
  }
}

export function useOwnerBadges(sections: Ref<string[]>) {
  const badges = ref<Record<string, OwnerBadge>>({});
  let timer: ReturnType<typeof setInterval> | null = null;

  async function refresh(): Promise<void> {
    const allowed = new Set(sections.value);
    const [contacts, health, refunds] = await Promise.all([
      settle(allowed.has('contacts'), () => fetchContactRequests({ status: 'new' })),
      settle(allowed.has('service'), () => fetchServiceHealth()),
      settle(allowed.has('payments'), () => fetchRefunds({ status: 'requested' })),
    ]);
    badges.value = ownerBadges({ contactsNew: contacts?.counts.new, health: health?.state, refundsRequested: refunds?.length });
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
