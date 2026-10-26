import { computed, watch, type Ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { Store } from '@/api/types';

const KEY = 'bw:selected-store';

function remembered(): string {
  try {
    return window.localStorage.getItem(KEY) ?? '';
  } catch {
    return '';
  }
}

function remember(id: string): void {
  try {
    window.localStorage.setItem(KEY, id);
  } catch {
    // A blocked storage only loses the convenience of carrying the choice to the next page.
  }
}

/**
 * The store a per-store page shows: the one in the address, the only store, or the one chosen last on another page.
 * With several stores and no choice it stays empty, so the page asks instead of silently showing the first store.
 */
export function useStoreChoice(stores: Ref<Store[]>) {
  const route = useRoute();
  const router = useRouter();
  const requested = computed(() => (typeof route.query.store === 'string' ? route.query.store : ''));

  const storeId = computed(() => {
    const known = (id: string) => id !== '' && stores.value.some((store) => store.id === id);

    if (known(requested.value)) return requested.value;
    if (stores.value.length === 1) return stores.value[0]!.id;

    return known(remembered()) ? remembered() : '';
  });

  watch(storeId, (id) => {
    if (id === '') return;
    remember(id);
    if (requested.value !== id) void router.replace({ query: { ...route.query, store: id } });
  }, { immediate: true });

  return { storeId };
}
