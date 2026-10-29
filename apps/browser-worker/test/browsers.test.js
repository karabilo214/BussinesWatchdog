import assert from 'node:assert/strict';
import { test } from 'node:test';

import { browserNames, launchBrowsers, launchOptions } from '../src/browsers.js';

const fakeEngine = (version, fail = false) => ({
  async launch(options) {
    if (fail) throw new Error('Executable doesn\'t exist\nmore');

    return { options, version: () => version, close: async () => {} };
  },
});

test('the worker always offers Chromium and only known engines', () => {
  assert.deepEqual(browserNames(undefined), ['chromium', 'firefox', 'webkit']);
  assert.deepEqual(browserNames('webkit, Firefox,opera,webkit'), ['chromium', 'webkit', 'firefox']);
  assert.deepEqual(browserNames(''), ['chromium']);
});

test('the Chromium sandbox flag and its proxy bypass syntax are given to Chromium only', () => {
  const config = { chromiumSandbox: true, chromiumExecutablePath: '', egressProxy: 'http://egress:3128' };

  assert.deepEqual(launchOptions('chromium', config), { headless: true, chromiumSandbox: true, proxy: { server: 'http://egress:3128', bypass: '<-loopback>' } });
  assert.deepEqual(launchOptions('firefox', config), { headless: true, proxy: { server: 'http://egress:3128', bypass: 'localhost,127.0.0.1' } });
  assert.deepEqual(launchOptions('webkit', { ...config, egressProxy: null }), { headless: true });
});

test('a browser that does not start is left out and its leases fall back to Chromium', async () => {
  const logged = [];
  const browsers = await launchBrowsers(
    { browsers: ['chromium', 'firefox', 'webkit'], chromiumSandbox: true, chromiumExecutablePath: '', egressProxy: null },
    (event, fields) => logged.push({ event, ...fields }),
    { chromium: fakeEngine('140.0'), firefox: fakeEngine('141.0'), webkit: fakeEngine('26.0', true) },
  );

  assert.deepEqual(browsers.versions, { chromium: 'chromium-140.0', firefox: 'firefox-141.0' });
  assert.equal(browsers.pick({ browser: 'firefox' }).version, 'firefox-141.0');
  assert.equal(browsers.pick({ browser: 'webkit' }).name, 'chromium');
  assert.equal(browsers.pick({}).name, 'chromium');
  assert.deepEqual(logged, [{ event: 'browser_unavailable', browser: 'webkit', error: 'Error', message: 'Executable doesn\'t exist' }]);
});

test('the worker does not start without Chromium', async () => {
  await assert.rejects(launchBrowsers(
    { browsers: ['chromium'], chromiumSandbox: true, chromiumExecutablePath: '', egressProxy: null },
    () => {},
    { chromium: fakeEngine('140.0', true) },
  ));
});
