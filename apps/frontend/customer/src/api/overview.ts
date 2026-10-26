import { request } from '@bw/api-client';
import type { Attention, Overview } from './types';

export async function getOverview(): Promise<Overview> {
  return (await request<Overview>('GET', '/api/v1/overview')).data;
}

export async function getAttention(): Promise<Attention> {
  return (await request<Attention>('GET', '/api/v1/attention')).data;
}
