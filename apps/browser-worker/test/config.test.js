import assert from 'node:assert/strict';
import { test } from 'node:test';
import { loadConfig } from '../src/config.js';

const base = { BW_API_URL: 'https://backend.internal', BW_WORKER_TOKEN: 'x'.repeat(40) };

test('production requires an egress proxy and refuses the local insecure mode', () => {
  assert.throws(() => loadConfig({ ...base, NODE_ENV: 'production' }), /BW_EGRESS_PROXY/);
  assert.throws(() => loadConfig({ ...base, NODE_ENV: 'production', BW_EGRESS_PROXY: 'http://egress:3128', BW_WORKER_INSECURE_LOCAL: '1' }), /INSECURE_LOCAL/);
  assert.equal(loadConfig({ ...base, NODE_ENV: 'production', BW_EGRESS_PROXY: 'http://egress:3128' }).egressProxy, 'http://egress:3128');
});

test('the sandbox is on unless explicitly disabled', () => {
  assert.equal(loadConfig(base).chromiumSandbox, true);
  assert.equal(loadConfig({ ...base, BW_CHROMIUM_SANDBOX: '0' }).chromiumSandbox, false);
});

test('missing credentials fail fast', () => {
  assert.throws(() => loadConfig({ BW_API_URL: 'https://backend.internal' }));
});
