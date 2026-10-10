import { request } from '@bw/api-client';
import type { AuthSession } from './types';

export async function fetchSession(): Promise<AuthSession> {
  return (await request<AuthSession>('GET', '/api/v1/auth/me')).data;
}

/** With MFA on, the password alone answers { mfa_required: true } and the code goes to completeMfaChallenge. */
export async function login(email: string, password: string, remember: boolean): Promise<AuthSession | { mfa_required: true }> {
  return (await request<AuthSession | { mfa_required: true }>('POST', '/api/v1/auth/login', { body: { email, password, remember } })).data;
}

export async function completeMfaChallenge(code: string): Promise<AuthSession> {
  return (await request<AuthSession>('POST', '/api/v1/auth/mfa/challenge', { body: { code } })).data;
}

export async function logout(): Promise<void> {
  await request<null>('POST', '/api/v1/auth/logout');
}
