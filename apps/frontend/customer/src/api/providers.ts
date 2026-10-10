import { request } from '@bw/api-client';
import type { Integration } from './types';

export async function connectStripe(storeId: string, input: { restricted_api_key: string; webhook_secret?: string; expected_account_id?: string }): Promise<Integration> {
  return (await request<Integration>('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/integrations/stripe`, { body: input })).data;
}

export async function setWebhookSecret(integrationId: string, webhookSecret: string): Promise<Integration> {
  return (await request<Integration>('PUT', `/api/v1/integrations/${encodeURIComponent(integrationId)}/webhook-secret`, { body: { webhook_secret: webhookSecret } })).data;
}

export async function syncProvider(integrationId: string, kind: 'delta' | 'audit' = 'delta'): Promise<{ status: string; emitted: number; unchanged: number; complete: boolean; error?: string; integration: Integration }> {
  return (await request<{ status: string; emitted: number; unchanged: number; complete: boolean; error?: string; integration: Integration }>('POST', `/api/v1/integrations/${encodeURIComponent(integrationId)}/sync`, { body: { kind } })).data;
}

export function webhookUrl(integrationId: string): string {
  return `${window.location.origin}/api/v1/webhooks/stripe/${integrationId}`;
}
