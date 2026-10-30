import { request } from '@bw/api-client';

export type SalesMode = 'live' | 'test';
export type ManualExclusionKind = 'promotion' | 'holiday';
export interface SalesExclusion {
  date: string;
  kind: ManualExclusionKind | 'pause' | 'data_gap';
  timezone: string;
  from: string;
  to: string;
  editable: boolean;
}
export interface SalesExclusions {
  timezone: string;
  min_date: string;
  max_date: string;
  mode: SalesMode;
  entries: SalesExclusion[];
}
export interface SalesExclusionInput {
  date: string;
  kind: ManualExclusionKind;
  mode: SalesMode;
  excluded: boolean;
}

export async function getSalesExclusions(storeId: string, mode: SalesMode): Promise<SalesExclusions> {
  return (await request<SalesExclusions>('GET', `/api/v1/stores/${encodeURIComponent(storeId)}/sales-exclusions`, { query: { mode } })).data;
}

export async function setSalesExclusion(storeId: string, input: SalesExclusionInput): Promise<SalesExclusions> {
  return (await request<SalesExclusions>('PUT', `/api/v1/stores/${encodeURIComponent(storeId)}/sales-exclusions`, { body: input })).data;
}
