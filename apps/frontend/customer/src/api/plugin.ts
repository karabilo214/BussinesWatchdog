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
