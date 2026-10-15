/** Parse a displayed price into minor units without converting money through JS Number. */
export function displayedMinor(text) {
  const raw = String(text ?? '').replace(/[\u00a0\u202f]/g, ' ');
  const token = raw.match(/[-−]?\d[\d.,'’ ]*/)?.[0]?.trim();
  if (!token) return null;

  const negative = /^[-−]/.test(token) || /[-−]\s*[^0-9]{0,4}\d/.test(raw);
  const unsigned = token.replace(/^[-−]/, '').replace(/[ '’]/g, '');
  const lastDot = unsigned.lastIndexOf('.');
  const lastComma = unsigned.lastIndexOf(',');
  const separator = Math.max(lastDot, lastComma);
  const fraction = separator < 0 ? '' : unsigned.slice(separator + 1);
  const hasFraction = /^[0-9]{1,2}$/.test(fraction);
  const whole = (hasFraction ? unsigned.slice(0, separator) : unsigned).replace(/[.,]/g, '');
  if (!/^\d+$/.test(whole)) return null;
  const minor = BigInt(whole) * 100n + (hasFraction ? BigInt(fraction.padEnd(2, '0')) : 0n);
  return negative ? -minor : minor;
}

export function sameProductPath(expected, actual) {
  try {
    const expectedUrl = new URL(expected);
    const actualUrl = new URL(actual, expectedUrl);
    if (expectedUrl.origin !== actualUrl.origin) return false;
    const path = (url) => url.pathname.replace(/\/$/, '') || '/';
    if (path(expectedUrl) !== path(actualUrl)) return false;
    for (const key of ['p', 'product_id', 'post']) {
      if (expectedUrl.searchParams.has(key)) return expectedUrl.searchParams.get(key) === actualUrl.searchParams.get(key);
    }
    return path(expectedUrl) !== '/' || expectedUrl.href === actualUrl.href;
  } catch {
    return false;
  }
}
