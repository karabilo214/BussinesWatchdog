import { request } from '@bw/api-client';
import { startAuthentication, startRegistration, browserSupportsWebAuthn, type PublicKeyCredentialCreationOptionsJSON, type PublicKeyCredentialRequestOptionsJSON } from '@simplewebauthn/browser';
import type { StaffMe } from '@/api';

const PROFILE = '/platform-api/v1/me/passkeys';
const LOGIN = '/platform-api/v1/auth/passkeys/login';

export interface StaffPasskey {
  id: number;
  name: string;
  created_at: string | null;
  last_used_at: string | null;
}

export const passkeysSupported = browserSupportsWebAuthn;

export function passkeyCancelled(error: unknown): boolean {
  return error instanceof Error && ['NotAllowedError', 'AbortError'].includes(error.name);
}

export async function signInWithPasskey(): Promise<StaffMe> {
  const { data } = await request<{ options: PublicKeyCredentialRequestOptionsJSON }>('GET', `${LOGIN}/options`);
  const credential = await startAuthentication({ optionsJSON: data.options });
  return (await request<StaffMe>('POST', LOGIN, { body: { credential } })).data;
}

export async function listStaffPasskeys(): Promise<StaffPasskey[]> {
  return (await request<{ data: StaffPasskey[] }>('GET', PROFILE)).data.data;
}

export async function addStaffPasskey(name: string, currentPassword: string, code: string): Promise<void> {
  await request('POST', `${PROFILE}/authorize`, { body: { current_password: currentPassword, code } });
  const { data } = await request<{ options: PublicKeyCredentialCreationOptionsJSON }>('GET', `${PROFILE}/register/options`);
  const credential = await startRegistration({ optionsJSON: data.options });
  await request('POST', `${PROFILE}/register`, { body: { name, credential } });
}

export async function deleteStaffPasskey(id: number, currentPassword: string, code: string): Promise<void> {
  await request('DELETE', `${PROFILE}/${id}`, { body: { current_password: currentPassword, code } });
}
