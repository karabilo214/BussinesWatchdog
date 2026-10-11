import { request, requestFile } from '@bw/api-client';
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

export async function connectPayPal(storeId: string, input: { environment: 'sandbox' | 'live'; client_id: string; client_secret: string; webhook_id?: string; write_access_acknowledged: boolean }): Promise<Integration> {
  return (await request<Integration>('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/integrations/paypal`, { body: input })).data;
}

export async function setPayPalWebhookId(integrationId: string, webhookId: string): Promise<Integration> {
  return (await request<Integration>('PUT', `/api/v1/integrations/${encodeURIComponent(integrationId)}/webhook-id`, { body: { webhook_id: webhookId } })).data;
}

export async function connectWooCommerceApi(storeId: string, input: { consumer_key: string; consumer_secret: string }): Promise<Integration> {
  return (await request<Integration>('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/integrations/woocommerce-api`, { body: input })).data;
}

export async function connectShopware(storeId: string, input: { access_key: string; secret_access_key: string; state_change_risk_acknowledged: boolean }): Promise<Integration> {
  return (await request<Integration>('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/integrations/shopware`, { body: input })).data;
}

export async function downloadShopwareApp(storeId: string): Promise<{ blob: Blob; filename: string | null }> {
  return requestFile(`/api/v1/stores/${encodeURIComponent(storeId)}/integrations/shopware-app`, { state_change_risk_acknowledged: true });
}

export function webhookUrl(integrationId: string): string {
  return `${window.location.origin}/api/v1/webhooks/stripe/${integrationId}`;
}
