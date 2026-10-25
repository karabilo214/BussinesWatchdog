import { ensureCsrfCookie, request } from '@bw/api-client';
import { Passkeys } from '@laravel/passkeys';
import type { AuthSession } from './types';

export async function fetchSession(): Promise<AuthSession> {
  return (await request<AuthSession>('GET', '/api/v1/auth/me')).data;
}

/** With MFA on, the password alone answers { mfa_required: true } and the code goes to completeMfaChallenge. */
export async function login(email: string, password: string, remember: boolean): Promise<AuthSession | { mfa_required: true }> {
  return (await request<AuthSession | { mfa_required: true }>('POST', '/api/v1/auth/login', { body: { email, password, remember } })).data;
}

export async function completeMfaChallenge(code: string, rememberDevice = false): Promise<AuthSession> {
  return (await request<AuthSession>('POST', '/api/v1/auth/mfa/challenge', { body: { code, remember_device: rememberDevice } })).data;
}

export async function loginWithPasskey(remember: boolean): Promise<AuthSession> {
  await ensureCsrfCookie();
  return (await Passkeys.verify({
    remember,
    routes: { options: '/api/v1/auth/passkeys/login/options', submit: '/api/v1/auth/passkeys/login' },
  })) as unknown as AuthSession;
}

export async function logout(): Promise<void> {
  await request<null>('POST', '/api/v1/auth/logout');
}
