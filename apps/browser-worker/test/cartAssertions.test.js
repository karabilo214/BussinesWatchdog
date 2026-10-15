import assert from 'node:assert/strict';
import test from 'node:test';
import { displayedMinor, sameProductPath } from '../src/adapter/cartAssertions.js';

test('displayed shop prices use integer minor units across common formats', () => {
  assert.equal(displayedMinor('€19.99'), 1999n);
  assert.equal(displayedMinor('1.234,56 €'), 123456n);
  assert.equal(displayedMinor('€0.00'), 0n);
  assert.equal(displayedMinor('−€1,00'), -100n);
  assert.equal(displayedMinor('not a price'), null);
});

test('cart product links match the selected product path', () => {
  assert.equal(sameProductPath('https://shop.example.test/detail/abc', 'https://shop.example.test/detail/abc?variant=one'), true);
  assert.equal(sameProductPath('https://shop.example.test/detail/abc', 'https://shop.example.test/detail/other'), false);
  assert.equal(sameProductPath('https://shop.example.test/detail/abc', 'https://other.example.test/detail/abc'), false);
  assert.equal(sameProductPath('https://shop.example.test/?p=123', 'https://shop.example.test/?p=123&variation_id=456'), true);
  assert.equal(sameProductPath('https://shop.example.test/?p=123', 'https://shop.example.test/?p=999'), false);
});
