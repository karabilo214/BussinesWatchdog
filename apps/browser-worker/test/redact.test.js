import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createErrorLog } from '../src/runAttempt.js';
import { failureCode, redactUrl } from '../src/redact.js';

test('urls keep only origin and a pseudonymised path', () => {
  assert.deepEqual(redactUrl('https://Shop.Example.test/checkout/order-received/12345/?key=wc_order_abc&email=jane@example.test#x'), {
    origin: 'https://shop.example.test',
    path: '/checkout/order-received/:n/',
  });
  assert.equal(redactUrl('https://shop.example.test/u/jane@example.test/').path, '/u/:email/');
  assert.equal(redactUrl('https://shop.example.test/t/0f8fad5b-d9cb-469f-a165-70867728950e').path, '/t/:id');
  assert.equal(redactUrl('https://shop.example.test/r/abcdefghijklmnopqrstuvwxyz012345').path, '/r/:token');
  assert.match(redactUrl('https://shop.example.test/?q=1').path, /^[^?#@]*$/);
});

test('failure codes keep only the network error class', () => {
  assert.equal(failureCode('net::ERR_CONNECTION_REFUSED at https://x'), 'ERR_CONNECTION_REFUSED');
  assert.equal(failureCode('something else'), 'request_failed');
});

test('the error log aggregates repeats and is bounded', () => {
  const log = createErrorLog();

  for (let i = 0; i < 3; i++) {
    log.add({ type: 'js_error', party: 'first', step_code: 'checkout', message_code: 'TypeError' });
  }

  for (let i = 0; i < 200; i++) {
    log.add({ type: 'http_5xx', party: 'first', origin: 'https://s', path: `/p${i}`, status: 500, step_code: 'cart' });
  }

  assert.equal(log.list()[0].count, 3);
  assert.equal(log.list().length, 100);
});
