import { ApiError, request } from '@bw/api-client';
import type { CheckoutLayoutFields, CreateStoreInput, Integration, Page, PairingCode, Store, StoreVerification, UpdateStoreInput, VerificationMethod } from './types';

export async function listStores(): Promise<Store[]> {
  return (await request<Page<Store>>('GET', '/api/v1/stores')).data.data;
}

export async function getStore(id: string): Promise<Store> {
  return (await request<Store>('GET', `/api/v1/stores/${encodeURIComponent(id)}`)).data;
}

export async function createStore(input: CreateStoreInput): Promise<Store> {
  return (await request<Store>('POST', '/api/v1/stores', { body: input })).data;
}

export async function detectStorePlatform(baseUrl: string): Promise<'woocommerce' | 'shopware' | null> {
  return (await request<{ detected_platform: 'woocommerce' | 'shopware' | null }>('POST', '/api/v1/stores/detect-platform', { body: { base_url: baseUrl } })).data.detected_platform;
}

export async function saveCheckoutLayout(storeId: string, layout: CheckoutLayoutFields): Promise<Store> {
  return (await request<Store>('PUT', `/api/v1/stores/${encodeURIComponent(storeId)}/checkout-layout`, { body: layout })).data;
}

export async function updateStore(store: Store, changes: UpdateStoreInput): Promise<Store> {
  return (await request<Store>('PATCH', `/api/v1/stores/${encodeURIComponent(store.id)}`, { body: changes, ifMatch: store.config_version })).data;
}

export async function deleteStore(storeId: string, name: string, currentPassword: string, code: string | null): Promise<void> {
  await request('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/delete`, {
    body: { name, current_password: currentPassword, code },
  });
}

/** Latest verification of the store, or null when none was ever started. */
export async function getVerification(storeId: string): Promise<StoreVerification | null> {
  try {
    return (await request<StoreVerification>('GET', `/api/v1/stores/${encodeURIComponent(storeId)}/verification`)).data;
  } catch (error) {
    if (error instanceof ApiError && error.status === 404) {
      return null;
    }

    throw error;
  }
}

export async function startVerification(storeId: string, method: VerificationMethod): Promise<StoreVerification> {
  return (await request<StoreVerification>('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/verify`, { body: { method } })).data;
}

export async function checkVerification(storeId: string): Promise<StoreVerification> {
  return (await request<StoreVerification>('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/verification/check`)).data;
}

export async function createPairingCode(storeId: string): Promise<PairingCode> {
  return (await request<PairingCode>('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/pairing-codes`)).data;
}

export async function listStoreIntegrations(storeId: string): Promise<Integration[]> {
  return (await request<Page<Integration>>('GET', `/api/v1/stores/${encodeURIComponent(storeId)}/integrations`)).data.data;
}

export async function revokeIntegration(integrationId: string): Promise<Integration> {
  return (await request<Integration>('POST', `/api/v1/integrations/${encodeURIComponent(integrationId)}/revoke`)).data;
}
