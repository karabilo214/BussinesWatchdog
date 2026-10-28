import { request } from '@bw/api-client';

export interface PluginPackage {
  available: boolean;
  version?: string;
  file?: string;
  size_bytes?: number;
  sha256?: string;
}

export const PLUGIN_DOWNLOAD_URL = '/api/v1/downloads/woocommerce-plugin/file';

export async function getPluginPackage(): Promise<PluginPackage> {
  return (await request<PluginPackage>('GET', '/api/v1/downloads/woocommerce-plugin')).data;
}

export interface PluginConnectStore {
  id: string;
  name: string;
  base_url: string;
  platform: string | null;
  order_source: 'woocommerce' | 'woocommerce_api' | 'shopware' | null;
}

export interface PluginConnectRequest {
  site: string;
  site_host: string;
  organization: string;
  expires_at: string;
  stores: PluginConnectStore[];
}

export async function getPluginConnect(): Promise<PluginConnectRequest> {
  return (await request<PluginConnectRequest>('GET', '/api/v1/plugin-connect')).data;
}

export async function approvePluginConnect(storeId: string): Promise<string> {
  return (await request<{ redirect_url: string }>('POST', '/api/v1/plugin-connect', { body: { store_id: storeId } })).data.redirect_url;
}

export async function cancelPluginConnect(): Promise<void> {
  await request('POST', '/api/v1/plugin-connect/cancel');
}

export function returnToWordPress(url: string): void {
  window.location.assign(url);
}
