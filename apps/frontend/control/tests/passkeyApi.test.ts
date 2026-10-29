import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { configureCsrf } from '@bw/api-client';
import { startAuthentication, startRegistration } from '@simplewebauthn/browser';
import { addStaffPasskey, signInWithPasskey } from '@/api/passkeys';

vi.mock('@simplewebauthn/browser', () => ({ startAuthentication: vi.fn(), startRegistration: vi.fn(), browserSupportsWebAuthn: vi.fn() }));
const fetchMock = vi.fn<typeof fetch>();
beforeEach(() => {
  vi.clearAllMocks();
  configureCsrf({ cookie: 'BW-PLATFORM-XSRF', endpoint: '/platform-api/v1/csrf-cookie' });
  document.cookie = 'BW-PLATFORM-XSRF=platform-token';
  document.cookie = 'XSRF-TOKEN=customer-token';
  vi.stubGlobal('fetch', fetchMock);
});
afterEach(() => vi.unstubAllGlobals());
function json(value: unknown) { return new Response(JSON.stringify(value), { status: 200, headers: { 'Content-Type': 'application/json' } }); }

describe('platform WebAuthn transport', () => {
  it('uses only platform endpoints and its own CSRF token for enrollment', async () => {
    fetchMock.mockResolvedValueOnce(json({ authorized: true })).mockResolvedValueOnce(json({ options: { challenge: 'enrollment' } })).mockResolvedValueOnce(json({ id: 7 }));
    vi.mocked(startRegistration).mockResolvedValue({ id: 'credential' } as never);
    await addStaffPasskey('Laptop', 'password', '123456');
    expect(fetchMock.mock.calls.map(([url]) => new URL(String(url)).pathname)).toEqual(['/platform-api/v1/me/passkeys/authorize', '/platform-api/v1/me/passkeys/register/options', '/platform-api/v1/me/passkeys/register']);
    for (const index of [0, 2]) expect(fetchMock.mock.calls[index]![1]?.headers).toMatchObject({ 'X-XSRF-TOKEN': 'platform-token' });
    expect(JSON.parse(String(fetchMock.mock.calls[2]![1]?.body))).toEqual({ name: 'Laptop', credential: { id: 'credential' } });
  });

  it('submits a browser assertion and returns the platform session without storing tokens', async () => {
    fetchMock.mockResolvedValueOnce(json({ options: { challenge: 'login' } })).mockResolvedValueOnce(json({ staff_id: 's1' }));
    vi.mocked(startAuthentication).mockResolvedValue({ id: 'assertion' } as never);
    expect(await signInWithPasskey()).toEqual({ staff_id: 's1' });
    expect(fetchMock.mock.calls.map(([url]) => new URL(String(url)).pathname)).toEqual(['/platform-api/v1/auth/passkeys/login/options', '/platform-api/v1/auth/passkeys/login']);
    expect(fetchMock.mock.calls[1]![1]?.headers).toMatchObject({ 'X-XSRF-TOKEN': 'platform-token' });
  });
});
