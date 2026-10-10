import { newIdempotencyKey, request } from '@bw/api-client';
import type { DeliveryStatus, NotificationChannel, NotificationDelivery, NotificationPreferences, Page } from './types';

export async function listChannels(): Promise<NotificationChannel[]> {
  return (await request<{ data: NotificationChannel[] }>('GET', '/api/v1/notification-channels')).data.data;
}

export async function createEmailChannel(label: string, email: string, preferences: Partial<NotificationPreferences>): Promise<NotificationChannel> {
  return (
    await request<NotificationChannel>('POST', '/api/v1/notification-channels', {
      body: { kind: 'email', label, destination_email: email, preferences },
      idempotencyKey: newIdempotencyKey(),
    })
  ).data;
}

export async function updateChannel(
  id: string,
  changes: { label?: string; enabled?: boolean; destination_email?: string; preferences?: Partial<NotificationPreferences> },
): Promise<NotificationChannel> {
  return (await request<NotificationChannel>('PATCH', `/api/v1/notification-channels/${encodeURIComponent(id)}`, { body: changes })).data;
}

export async function verifyChannel(id: string, code: string): Promise<NotificationChannel> {
  return (await request<NotificationChannel>('POST', `/api/v1/notification-channels/${encodeURIComponent(id)}/verify`, { body: { code } })).data;
}

export async function resendChannelCode(id: string): Promise<void> {
  await request('POST', `/api/v1/notification-channels/${encodeURIComponent(id)}/verification-code`);
}

export async function testChannel(id: string): Promise<NotificationDelivery> {
  return (await request<NotificationDelivery>('POST', `/api/v1/notification-channels/${encodeURIComponent(id)}/test`)).data;
}

export async function listDeliveries(filters: { channel_id?: string; status?: DeliveryStatus; cursor?: string }): Promise<Page<NotificationDelivery>> {
  return (await request<Page<NotificationDelivery>>('GET', '/api/v1/notification-deliveries', { query: { ...filters, limit: 30 } })).data;
}
