import { request } from '@bw/api-client';
import type { AuthSession, InvitationLookup, Locale, MfaSetup, MfaState, TeamInvitation, TeamMember, TeamRole } from './types';

export async function requestPasswordReset(email: string): Promise<void> {
  await request('POST', '/api/v1/auth/password-reset/request', { body: { email } });
}

export async function completePasswordReset(email: string, token: string, password: string): Promise<void> {
  await request('POST', '/api/v1/auth/password-reset/complete', { body: { email, token, password, password_confirmation: password } });
}

export async function resendEmailVerification(): Promise<void> {
  await request('POST', '/api/v1/auth/email-verification/resend');
}

export async function updateProfile(changes: { name?: string; locale?: Locale }): Promise<AuthSession> {
  return (await request<AuthSession>('PATCH', '/api/v1/auth/me', { body: changes })).data;
}

export async function changePassword(currentPassword: string, password: string): Promise<void> {
  await request('POST', '/api/v1/auth/password', { body: { current_password: currentPassword, password, password_confirmation: password } });
}

export async function listMembers(): Promise<{ data: TeamMember[]; assignable_roles: Exclude<TeamRole, 'owner'>[] }> {
  return (await request<{ data: TeamMember[]; assignable_roles: Exclude<TeamRole, 'owner'>[] }>('GET', '/api/v1/memberships')).data;
}

export async function changeMemberRole(userId: string, role: Exclude<TeamRole, 'owner'>): Promise<TeamMember> {
  return (await request<TeamMember>('PATCH', `/api/v1/memberships/${encodeURIComponent(userId)}`, { body: { role } })).data;
}

export async function removeMember(userId: string): Promise<void> {
  await request('DELETE', `/api/v1/memberships/${encodeURIComponent(userId)}`);
}

export async function listInvitations(): Promise<TeamInvitation[]> {
  return (await request<{ data: TeamInvitation[] }>('GET', '/api/v1/invitations')).data.data;
}

export async function inviteMember(email: string, role: Exclude<TeamRole, 'owner'>): Promise<TeamInvitation> {
  return (await request<TeamInvitation>('POST', '/api/v1/invitations', { body: { email, role } })).data;
}

export async function revokeInvitation(id: string): Promise<TeamInvitation> {
  return (await request<TeamInvitation>('POST', `/api/v1/invitations/${encodeURIComponent(id)}/revoke`)).data;
}

export async function lookupInvitation(token: string): Promise<InvitationLookup> {
  return (await request<InvitationLookup>('GET', '/api/v1/invitations/lookup', { query: { token } })).data;
}

export async function acceptInvitation(token: string): Promise<TeamMember> {
  return (await request<TeamMember>('POST', '/api/v1/invitations/accept', { body: { token } })).data;
}

export async function registerWithInvitation(input: { token: string; name: string; email: string; password: string; locale: Locale }): Promise<AuthSession> {
  return (
    await request<AuthSession>('POST', '/api/v1/auth/register', {
      body: { name: input.name, email: input.email, password: input.password, password_confirmation: input.password, locale: input.locale, invitation_token: input.token },
    })
  ).data;
}

export async function fetchMfa(): Promise<MfaState> {
  return (await request<MfaState>('GET', '/api/v1/auth/mfa')).data;
}

export async function beginMfaSetup(currentPassword: string): Promise<MfaSetup> {
  return (await request<MfaSetup>('POST', '/api/v1/auth/mfa/setup', { body: { current_password: currentPassword } })).data;
}

export async function confirmMfa(code: string): Promise<MfaState & { recovery_codes: string[] }> {
  return (await request<MfaState & { recovery_codes: string[] }>('POST', '/api/v1/auth/mfa/confirm', { body: { code } })).data;
}

export async function disableMfa(currentPassword: string, code: string): Promise<MfaState> {
  return (await request<MfaState>('POST', '/api/v1/auth/mfa/disable', { body: { current_password: currentPassword, code } })).data;
}

export async function regenerateRecoveryCodes(currentPassword: string, code: string): Promise<MfaState & { recovery_codes: string[] }> {
  return (await request<MfaState & { recovery_codes: string[] }>('POST', '/api/v1/auth/mfa/recovery-codes', { body: { current_password: currentPassword, code } })).data;
}

export async function transferOwnership(targetUserId: string, currentPassword: string, code: string | null): Promise<void> {
  await request('POST', '/api/v1/ownership-transfer', { body: { target_user_id: targetUserId, current_password: currentPassword, code } });
}
