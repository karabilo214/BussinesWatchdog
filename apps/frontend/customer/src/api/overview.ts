import { request } from '@bw/api-client';
import type { Overview } from './types';

export async function getOverview(): Promise<Overview> {
  return (await request<Overview>('GET', '/api/v1/overview')).data;
}
