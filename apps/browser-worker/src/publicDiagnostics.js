const BROWSER_RESOURCE_ERROR = /^Failed to load resource:/i;
const CANCELLED_REQUEST = new Set(['ERR_ABORTED', 'ERR_BLOCKED_BY_CLIENT']);

export function reportableRequestFailure(code, party, resourceType) {
  if (CANCELLED_REQUEST.has(code) || party !== 'first') {
    return false;
  }

  return ['document', 'xhr', 'fetch', 'script', 'stylesheet', 'image'].includes(resourceType);
}

export function publicConsoleFinding(message, party) {
  if (party !== 'first' || BROWSER_RESOURCE_ERROR.test(message.text())) {
    return null;
  }

  const value = message.text().trim();

  // Console output is arbitrary shop data. Keep only stable exception names, never raw text.
  const exception = /(?:Uncaught\s+)?(TypeError|ReferenceError|SyntaxError|RangeError|URIError|Error)\b/.exec(value);

  return exception ? exception[1] : (message.type?.() === 'warning' ? 'console_warning' : 'console_error');
}

export function errorSourceUrl(error) {
  const match = /https?:\/\/[^\s)]+/.exec(String(error?.stack ?? ''));

  return match ? match[0].replace(/:\d+:\d+$/, '') : null;
}
