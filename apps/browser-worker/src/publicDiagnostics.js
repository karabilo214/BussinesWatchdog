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

export function publicConsoleCause(message) {
  const value = message.text();
  if (/MIME type .*text\/html|strict MIME type checking/i.test(value)) return 'mime_type_mismatch';
  if (/refused to (?:load|execute|apply).*content security policy|CSP directive/i.test(value)) return 'content_security_policy';
  if (/deprecated|deprecation|jquery migrate/i.test(value)) return 'deprecated_api';
  if (/failed to load|404|not found|net::ERR_/i.test(value)) return 'resource_load';
  if (/cookie|consent|tracking|analytics|pixel/i.test(value)) return 'tracking_or_consent';
  if (/TypeError|ReferenceError|SyntaxError|RangeError|URIError|Uncaught/i.test(value)) return 'script_exception';
  return 'unclassified_script';
}

export function publicConsoleSourceUrl(message) {
  const match = /https?:\/\/[^\s'"<>]+/.exec(message.text());
  return match ? match[0] : null;
}

export function errorSourceUrl(error) {
  const match = /https?:\/\/[^\s)]+/.exec(String(error?.stack ?? ''));

  return match ? match[0].replace(/:\d+:\d+$/, '') : null;
}
