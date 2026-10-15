import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import { errorSourceUrl, publicConsoleCause, publicConsoleFinding, publicConsoleSourceUrl, reportableRequestFailure } from '../src/publicDiagnostics.js';

describe('public storefront diagnostics', () => {
  it('does not mistake navigation cancellations or browser policy messages for store failures', () => {
    for (const code of ['ERR_ABORTED', 'ERR_BLOCKED_BY_CLIENT']) {
      assert.equal(reportableRequestFailure(code, 'first', 'image'), false);
      assert.equal(reportableRequestFailure(code, 'first', 'fetch'), false);
    }

    assert.equal(publicConsoleFinding({ text: () => 'Failed to load resource: net::ERR_BLOCKED_BY_CLIENT.Inspector' }, 'first'), null);
    assert.equal(publicConsoleFinding({ text: () => 'Uncaught TypeError: private@example.com' }, 'first'), 'TypeError');
  });

  it('keeps actionable first-party failures without leaking arbitrary console text', () => {
    assert.equal(reportableRequestFailure('ERR_CONNECTION_RESET', 'first', 'fetch'), true);
    assert.equal(reportableRequestFailure('ERR_NAME_NOT_RESOLVED', 'first', 'image'), true);
    assert.equal(reportableRequestFailure('ERR_CONNECTION_RESET', 'third', 'fetch'), false);
    assert.equal(publicConsoleFinding({ text: () => 'cart could not load secret token' }, 'first'), 'console_error');
    assert.equal(publicConsoleFinding({ text: () => 'Deprecated storefront API', type: () => 'warning' }, 'first'), 'console_warning');
    assert.equal(publicConsoleFinding({ text: () => 'Uncaught ReferenceError: variable' }, 'third'), null);
    assert.equal(errorSourceUrl({ stack: 'TypeError: broken\n at render (https://store.example.com/assets/app.js?token=secret:14:2)' }), 'https://store.example.com/assets/app.js?token=secret');
  });

  it('classifies causes without storing raw console messages', () => {
    assert.equal(publicConsoleCause({ text: () => 'Deprecated storefront API for private@example.com' }), 'deprecated_api');
    assert.equal(publicConsoleCause({ text: () => 'Uncaught TypeError: private@example.com' }), 'script_exception');
    assert.equal(publicConsoleCause({ text: () => 'unknown private@example.com' }), 'unclassified_script');
    assert.equal(publicConsoleCause({ text: () => "Refused to execute script because its MIME type ('text/html') is not executable" }), 'mime_type_mismatch');
    assert.equal(publicConsoleSourceUrl({ text: () => "Refused to execute script from 'https://store.example.com/assets/app.js?token=secret'" }), 'https://store.example.com/assets/app.js?token=secret');
  });
});
