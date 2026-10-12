import { request } from '@bw/api-client';

export type StaffRole = 'platform_owner' | 'platform_admin' | 'platform_support' | 'platform_billing';

export interface StaffMe {
  staff_id: string;
  name: string;
  email: string;
  locale: string;
  role: StaffRole;
  mfa_enabled: true;
  capabilities: string[];
  billing_mode: 'test' | 'live';
  session: { idle_minutes: number; absolute_hours: number };
}

export async function fetchMe(): Promise<StaffMe> {
  return (await request<StaffMe>('GET', '/platform-api/v1/me')).data;
}

export async function login(email: string, password: string): Promise<void> {
  await request<{ mfa_required: true }>('POST', '/platform-api/v1/auth/login', { body: { email, password } });
}

export async function submitMfa(code: string): Promise<StaffMe> {
  return (await request<StaffMe>('POST', '/platform-api/v1/auth/mfa', { body: { code } })).data;
}

export async function logout(): Promise<void> {
  await request<null>('POST', '/platform-api/v1/auth/logout');
}
