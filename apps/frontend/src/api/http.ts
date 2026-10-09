export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly code: string,
    public readonly fieldErrors: Record<string, string[]> = {},
    public readonly requestId: string | null = null,
  ) {
    super(code);
  }
}

export interface ApiResponse<T> {
  data: T;
  etag: string | null;
  requestId: string | null;
}

export interface RequestOptions {
  body?: unknown;
  query?: Record<string, string | number | undefined>;
  ifMatch?: string | number;
  idempotencyKey?: string;
}

const MUTATIONS = new Set(['POST', 'PUT', 'PATCH', 'DELETE']);

let unauthorizedHandler: (() => void) | null = null;

/** Called once by the app so an expired session sends the user back to the login page. */
export function onUnauthorized(handler: () => void): void {
  unauthorizedHandler = handler;
}

function readCookie(name: string): string | null {
  const match = document.cookie.split('; ').find((part) => part.startsWith(`${name}=`));

  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

/** Sanctum SPA: the XSRF-TOKEN cookie must exist before the first state-changing request. */
async function ensureCsrfCookie(): Promise<string> {
  let token = readCookie('XSRF-TOKEN');

  if (token === null) {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    token = readCookie('XSRF-TOKEN');
  }

  if (token === null) {
    throw new ApiError(0, 'csrf_unavailable');
  }

  return token;
}

export async function request<T>(method: string, path: string, options: RequestOptions = {}): Promise<ApiResponse<T>> {
  const verb = method.toUpperCase();
  const headers: Record<string, string> = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
  const url = new URL(path, window.location.origin);

  for (const [key, value] of Object.entries(options.query ?? {})) {
    if (value !== undefined && value !== '') {
      url.searchParams.set(key, String(value));
    }
  }

  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }

  if (MUTATIONS.has(verb)) {
    headers['X-XSRF-TOKEN'] = await ensureCsrfCookie();
  }

  if (options.ifMatch !== undefined) {
    headers['If-Match'] = `"${String(options.ifMatch).replace(/"/g, '')}"`;
  }

  if (options.idempotencyKey !== undefined) {
    headers['Idempotency-Key'] = options.idempotencyKey;
  }

  let response: Response;

  try {
    response = await fetch(url.toString(), {
      method: verb,
      credentials: 'same-origin',
      headers,
      body: options.body === undefined ? undefined : JSON.stringify(options.body),
    });
  } catch {
    throw new ApiError(0, 'network_unavailable');
  }

  const requestId = response.headers.get('X-Request-ID');
  const payload = response.status === 204 ? null : await response.json().catch(() => null);

  if (!response.ok) {
    if (response.status === 401 && unauthorizedHandler !== null) {
      unauthorizedHandler();
    }

    const code = typeof payload?.code === 'string' ? payload.code : `http_${response.status}`;
    const fieldErrors = payload?.errors && typeof payload.errors === 'object' && !Array.isArray(payload.errors) ? payload.errors : {};

    throw new ApiError(response.status, response.status === 422 && payload?.code === undefined ? 'validation_failed' : code, fieldErrors, requestId);
  }

  return { data: payload as T, etag: response.headers.get('ETag'), requestId };
}

export function newIdempotencyKey(): string {
  return crypto.randomUUID();
}
