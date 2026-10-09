import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError, onUnauthorized, request } from '@bw/api-client';

function json(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(body === null ? null : JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', ...headers } });
}

describe('api client', () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('fetches the Sanctum CSRF cookie before the first mutation and sends it back', async () => {
    fetchMock.mockImplementation(async (url: string) => {
      if (url === '/sanctum/csrf-cookie') {
        document.cookie = 'XSRF-TOKEN=abc%3D%3D; path=/';

        return new Response(null, { status: 204 });
      }

      return json(200, { ok: true }, { 'X-Request-ID': 'r-1', ETag: '"3"' });
    });

    const response = await request<{ ok: boolean }>('PATCH', '/api/v1/stores/1', { body: { name: 'x' }, ifMatch: 2, idempotencyKey: 'k-1' });

    expect(fetchMock.mock.calls[0]![0]).toBe('/sanctum/csrf-cookie');
    const init = fetchMock.mock.calls[1]![1] as RequestInit;
    const headers = init.headers as Record<string, string>;
    expect(headers['X-XSRF-TOKEN']).toBe('abc==');
    expect(headers['If-Match']).toBe('"2"');
    expect(headers['Idempotency-Key']).toBe('k-1');
    expect(init.credentials).toBe('same-origin');
    expect(response).toEqual({ data: { ok: true }, etag: '"3"', requestId: 'r-1' });
  });

  it('does not fetch a CSRF cookie for reads', async () => {
    fetchMock.mockResolvedValue(json(200, { data: [], next_cursor: null }));

    await request('GET', '/api/v1/stores', { query: { limit: 10, cursor: undefined } });

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(fetchMock.mock.calls[0]![0]).toBe(`${window.location.origin}/api/v1/stores?limit=10`);
  });

  it('turns problem responses into ApiError with code, field errors and request id', async () => {
    fetchMock.mockResolvedValue(json(409, { code: 'version_conflict', message: 'x' }, { 'X-Request-ID': 'r-2' }));
    await expect(request('GET', '/api/v1/x')).rejects.toMatchObject({ status: 409, code: 'version_conflict', requestId: 'r-2' });

    fetchMock.mockResolvedValue(json(422, { message: 'x', errors: { email: ['bad'] } }));
    await expect(request('GET', '/api/v1/x')).rejects.toMatchObject({ status: 422, code: 'validation_failed', fieldErrors: { email: ['bad'] } });

    fetchMock.mockRejectedValue(new TypeError('offline'));
    await expect(request('GET', '/api/v1/x')).rejects.toBeInstanceOf(ApiError);
  });

  it('notifies the app when the session has expired', async () => {
    const handler = vi.fn();
    onUnauthorized(handler);
    fetchMock.mockResolvedValue(json(401, { message: 'Unauthenticated.' }));

    await expect(request('GET', '/api/v1/stores')).rejects.toMatchObject({ status: 401 });
    expect(handler).toHaveBeenCalledOnce();
  });
});
