export const REDACTION_VERSION = 'r1';

const LONG_NUMBER = /\b\d{4,}\b/g;
const UUID = /[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/gi;
const TOKEN = /[A-Za-z0-9_-]{24,}/g;
const EMAIL = /[^/@\s]+@[^/@\s]+/g;

/**
 * Origin and path only: the query string and fragment are dropped (they may carry tokens,
 * order keys or e-mail addresses), and identifier-like path segments are pseudonymised.
 */
export function redactUrl(url) {
  try {
    const parsed = new URL(url);
    const path = decodeURIComponent(parsed.pathname)
      .replace(EMAIL, ':email')
      .replace(UUID, ':id')
      .replace(TOKEN, ':token')
      .replace(LONG_NUMBER, ':n')
      .replace(/[?#@]/g, '');

    return { origin: `${parsed.protocol}//${parsed.host}`.toLowerCase(), path: path.slice(0, 256) || '/' };
  } catch {
    return { origin: 'invalid', path: '/' };
  }
}

export function errorName(error) {
  const name = typeof error?.name === 'string' && /^[A-Za-z]{1,40}$/.test(error.name) ? error.name : 'Error';

  return name;
}

export function failureCode(text) {
  const match = /net::(ERR_[A-Z_]+)/.exec(String(text ?? ''));

  return match ? match[1] : 'request_failed';
}
