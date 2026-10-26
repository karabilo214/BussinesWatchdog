import type { Store } from '@/api/types';

export type Verdict = 'no_stores' | 'problems' | 'incomplete' | 'all_clear' | 'restricted';

/** A store is fully covered only when every source is fresh and its checkout check passes (spec §24, ADR 0018). */
export function storeFullyCovered(store: Store): boolean {
  const coverage = store.coverage;

  return (
    coverage.connector.state === 'fresh' &&
    coverage.money.state === 'reconciling' &&
    coverage.payment_attempts.state === 'observing' &&
    coverage.browser_checks.state === 'passing'
  );
}

/** Without incident access (null) the overall verdict is not shown: hidden incidents are not "no problems". */
export function verdict(stores: Store[], activeIncidents: number | null): Verdict {
  if (stores.length === 0) return 'no_stores';
  if (activeIncidents === null) return 'restricted';
  if (activeIncidents > 0) return 'problems';

  return stores.every(storeFullyCovered) ? 'all_clear' : 'incomplete';
}

/** The latest successful checkout check across stores (ISO strings compare chronologically). */
export function lastSuccessfulCheck(stores: Store[]): string | null {
  return stores.map((store) => store.last_successful_check_at).filter((value): value is string => value !== null).sort().at(-1) ?? null;
}
