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

let csrf = { cookie: 'XSRF-TOKEN', endpoint: '/sanctum/csrf-cookie' };

/** The control panel has its own session and CSRF cookie; the customer app keeps the Sanctum defaults. */
export function configureCsrf(options: { cookie: string; endpoint: string }): void {
  csrf = { ...options };
}

/** The CSRF cookie must exist before the first state-changing request. */
async function ensureCsrfCookie(): Promise<string> {
  let token = readCookie(csrf.cookie);

  if (token === null) {
    await fetch(csrf.endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    token = readCookie(csrf.cookie);
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

/** A POST that answers with a file (for example an app archive); errors are JSON like every other request. */
export async function requestFile(path: string, body: unknown): Promise<{ blob: Blob; filename: string | null }> {
  let response: Response;

  try {
    response = await fetch(new URL(path, window.location.origin).toString(), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json, application/zip', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': await ensureCsrfCookie() },
      body: JSON.stringify(body),
    });
  } catch {
    throw new ApiError(0, 'network_unavailable');
  }

  if (!response.ok) {
    const payload = await response.json().catch(() => null);

    if (response.status === 401 && unauthorizedHandler !== null) {
      unauthorizedHandler();
    }

    throw new ApiError(response.status, typeof payload?.code === 'string' ? payload.code : `http_${response.status}`, {}, response.headers.get('X-Request-ID'));
  }

  const disposition = response.headers.get('Content-Disposition') ?? '';

  return { blob: await response.blob(), filename: /filename="([^"]+)"/.exec(disposition)?.[1] ?? null };
}

/** UUID v4. crypto.randomUUID exists only in secure contexts (HTTPS, localhost); getRandomValues works everywhere. */
export function newIdempotencyKey(): string {
  if (typeof crypto.randomUUID === 'function' && globalThis.isSecureContext !== false) {
    return crypto.randomUUID();
  }

  const bytes = crypto.getRandomValues(new Uint8Array(16));
  bytes[6] = (bytes[6]! & 0x0f) | 0x40;
  bytes[8] = (bytes[8]! & 0x3f) | 0x80;
  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}
