import { computed, type ComputedRef } from 'vue';
import { useRouter, type RouteLocationRaw } from 'vue-router';

/**
 * The "← list" link of a detail page: back to the exact list page the user came from (same store, filters and page),
 * or to the fallback when the page was opened directly or from somewhere else.
 */
export function useBackLink(listName: string, fallback: () => RouteLocationRaw): ComputedRef<RouteLocationRaw> {
  const router = useRouter();
  const back = typeof window === 'undefined' ? null : (window.history.state?.back as unknown);

  return computed(() => {
    if (typeof back === 'string' && router.resolve(back).name === listName) {
      return back;
    }

    return fallback();
  });
}
