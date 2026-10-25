import { request } from '@bw/api-client';

export type GrantScope = 'read_diagnostics' | 'read_artifacts' | 'request_recheck';
export type GrantState = 'pending' | 'approved' | 'denied' | 'revoked' | 'expired';

export interface SupportGrant {
  id: string;
  store_id: string;
  store_name: string | null;
  staff: { id: string; name: string; email: string } | null;
  scopes: GrantScope[];
  state: GrantState;
  reason: string;
  case_reference: string;
  duration_hours: number;
  version: number;
  created_at: string;
  approved_at: string | null;
  expires_at: string | null;
  revoked_at: string | null;
}

export interface GrantActivity {
  id: string;
  action: string;
  at: string;
  staff_id: string | null;
  staff_name: string | null;
  target_type: string | null;
  target_id: string | null;
}

export async function listSupportGrants(): Promise<SupportGrant[]> {
  return (await request<{ data: SupportGrant[] }>('GET', '/api/v1/support-grants')).data.data;
}

export async function requestSupportGrant(input: { store_id: string; staff_email: string; scopes: GrantScope[]; duration_hours: number; reason: string }): Promise<SupportGrant> {
  return (await request<SupportGrant>('POST', '/api/v1/support-grants', { body: input })).data;
}

export async function decideSupportGrant(id: string, decision: 'approve' | 'deny' | 'revoke'): Promise<SupportGrant> {
  return (await request<SupportGrant>('POST', `/api/v1/support-grants/${encodeURIComponent(id)}/${decision}`)).data;
}

export async function listSupportActivity(id: string): Promise<GrantActivity[]> {
  return (await request<{ data: GrantActivity[] }>('GET', `/api/v1/support-grants/${encodeURIComponent(id)}/activity`)).data.data;
}
