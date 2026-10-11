import { newIdempotencyKey, request } from '@bw/api-client';
import type { ExportFile } from './types';

export async function createExport(storeId: string, input: { kind: 'findings' | 'incidents' | 'transactions'; filters?: Record<string, unknown> }): Promise<ExportFile> {
  return (
    await request<ExportFile>('POST', `/api/v1/stores/${encodeURIComponent(storeId)}/exports`, {
      body: { kind: input.kind, format: 'csv', filters: input.filters ?? {} },
      idempotencyKey: newIdempotencyKey(),
    })
  ).data;
}

export function openExportDownload(file: ExportFile): boolean {
  if (file.download_url !== null) {
    window.location.assign(file.download_url);

    return true;
  }

  return false;
}
