import { request } from './http';
import type { Page, Store } from './types';

export async function listStores(): Promise<Store[]> {
  return (await request<Page<Store>>('GET', '/api/v1/stores')).data.data;
}
