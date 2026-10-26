import { computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';

export type StoreDetailKind = 'order' | 'check' | 'incident';
const TABS = { order: 'orders', check: 'checks', incident: 'incidents' } as const;
const KINDS = Object.keys(TABS) as StoreDetailKind[];

/** The selected store object belongs to the URL, including browser back/forward and direct links. */
export function useStoreDetailRoute() {
  const route = useRoute();
  const router = useRouter();
  const selected = computed(() => {
    const kind = KINDS.find((key) => route.query.tab === TABS[key] && typeof route.query[key] === 'string' && route.query[key] !== '');
    return kind ? { kind, id: route.query[kind] as string } : null;
  });

  function listQuery() {
    const query = { ...route.query };
    for (const key of KINDS) delete query[key];
    return query;
  }

  function location(kind: StoreDetailKind, id: string) {
    return { path: route.path, hash: route.hash, query: { ...listQuery(), tab: TABS[kind], [kind]: id }, state: { storeDetailReturn: route.fullPath } };
  }

  function close(): void {
    const state = router.options.history.state;
    if (typeof state.storeDetailReturn === 'string' && state.back === state.storeDetailReturn) {
      router.back();
    } else {
      void router.replace({ path: route.path, hash: route.hash, query: listQuery(), state: { storeDetailReturn: null } });
    }
  }

  async function prepareDirectLink(): Promise<void> {
    if (!selected.value) return;
    const state = router.options.history.state;
    if (typeof state.storeDetailReturn === 'string' && state.back === state.storeDetailReturn) return;

    const target = { path: route.path, hash: route.hash, query: { ...route.query } };
    await router.replace({ ...target, query: listQuery(), state: { storeDetailReturn: null } });
    const back = route.fullPath;
    await router.push({ ...target, state: { storeDetailReturn: back } });
  }

  return { selected, location, close, prepareDirectLink };
}
