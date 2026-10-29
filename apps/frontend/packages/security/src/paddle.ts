/** Paddle's parent-document sinks: CSS text and two reviewed script URLs, never arbitrary HTML or JS. */
// CSS emitted by the real Paddle.js parent-document loader/inline checkout (verified 2026-10-29).
// Whitespace is normalized for comparison only. Every other HTML/text value is rejected.
const PADDLE_STYLES = new Set([
  '',
  '@-webkit-keyframes rotate { 0% { -webkit-transform: rotate(45deg); } 100% { -webkit-transform: rotate(405deg); } } @keyframes rotate { from { transform: rotate(45deg); } to { transform: rotate(405deg); } }',
  '.paddle-frame-inline::-webkit-scrollbar { display: none !important; }',
]);

export function paddleHtmlText(value: string): string {
  if (!PADDLE_STYLES.has(value.replace(/\s+/g, ' ').trim())) throw new TypeError('Unapproved Paddle HTML/style value');
  return value;
}

export function paddleScriptUrl(value: string): string {
  if (value === 'https://cdn.paddle.com/paddle/v2/paddle.js') return value;
  const url = new URL(value);
  const keys = [...url.searchParams.keys()];
  if (url.origin === 'https://public.profitwell.com' && url.pathname === '/js/profitwell.js'
    && url.username === '' && url.password === '' && url.hash === ''
    && keys.length === 1 && keys[0] === 'auth' && /^[a-zA-Z0-9_]+$/.test(url.searchParams.get('auth') ?? '')) return value;
  throw new TypeError('Unapproved Paddle script URL');
}

type Factory = { createPolicy(name: string, options: { createHTML: (value: string) => string; createScriptURL: (value: string) => string }): unknown };

export function installPaddleTrustedTypes(): void {
  const factory = (window as unknown as { trustedTypes?: Factory }).trustedTypes;
  factory?.createPolicy('default', { createHTML: paddleHtmlText, createScriptURL: paddleScriptUrl });
}
