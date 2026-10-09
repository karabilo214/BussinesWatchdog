import { request } from './http';
import type { AuthSession } from './types';

export async function fetchSession(): Promise<AuthSession> {
  return (await request<AuthSession>('GET', '/api/v1/auth/me')).data;
}

export async function login(email: string, password: string, remember: boolean): Promise<AuthSession> {
  return (await request<AuthSession>('POST', '/api/v1/auth/login', { body: { email, password, remember } })).data;
}

export async function logout(): Promise<void> {
  await request<null>('POST', '/api/v1/auth/logout');
}
