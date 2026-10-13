import { request } from '@bw/api-client';

export interface MaintenanceNotice {
  starts_at: string;
  ends_at: string;
  message: string;
  active: boolean;
}

export async function getMaintenance(): Promise<MaintenanceNotice | null> {
  return (await request<{ notice: MaintenanceNotice | null }>('GET', '/api/v1/maintenance')).data.notice;
}
