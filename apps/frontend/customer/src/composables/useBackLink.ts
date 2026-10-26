import { computed, type ComputedRef } from 'vue';
import { useRouter, type RouteLocationRaw } from 'vue-router';

/** Pages a detail page can be opened from, with the label of the "←" link back to them. */
const ORIGINS: Record<string, string> = {
  overview: 'nav.overview',
  incidents: 'nav.incidents',
  reconciliation: 'nav.reconciliation',
  checks: 'nav.checks',
  notifications: 'nav.notifications',
  store: 'nav.back.store',
  incident: 'nav.back.incident',
  order: 'nav.back.order',
  'check-run': 'nav.back.check_run',
};

export interface BackLink {
  href: ComputedRef<string>;
  label: ComputedRef<string>;
  go: (event: MouseEvent) => void;
}

/**
 * The "←" link of a detail page: back to where the user came from — the same list with its store, filters and page,
 * the overview or a store — through browser history, so the scroll position returns too. Opened directly, it leads
 * to the fallback list.
 */
export function useBackLink(listName: string, fallback: () => RouteLocationRaw): BackLink {
  const router = useRouter();
  const back = typeof window === 'undefined' ? null : (window.history.state?.back as unknown);
  const origin = typeof back === 'string' ? String(router.resolve(back).name ?? '') : '';
  const known = origin in ORIGINS;

  return {
    href: computed(() => (known ? router.resolve(back as string).href : router.resolve(fallback()).href)),
    label: computed(() => ORIGINS[known ? origin : listName] ?? 'nav.overview'),
    go(event: MouseEvent) {
      if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || event.button !== 0) return;
      event.preventDefault();

      if (known) {
        router.back();
      } else {
        void router.push(fallback());
      }
    },
  };
}
