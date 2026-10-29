import assert from 'node:assert/strict';
import { after, before, describe, it } from 'node:test';

import { chromium, firefox, webkit } from 'playwright';

import { waitForPreloader } from '../src/adapter/common.js';
import { AGE_CONFIRM_LABEL, COOKIE_ACKNOWLEDGE, COOKIE_REJECT, installInterruptionHandlers, interceptedOutcome } from '../src/adapter/interruptions.js';

const cover = 'position:fixed;inset:0;z-index:9999;background:#eee';
const buyButton = '<form class="buy-widget"><button class="btn-buy" type="button" onclick="document.body.dataset.bought=1">In den Warenkorb</button></form>';

const engines = { chromium, firefox, webkit };
const browsers = {};

before(async () => {
  for (const [name, engine] of Object.entries(engines)) {
    browsers[name] = await engine.launch().catch(() => null);
  }
});

after(async () => {
  await Promise.all(Object.values(browsers).map((browser) => browser?.close()));
});

async function pageIn(t, name, html) {
  const browser = browsers[name];

  if (!browser) {
    t.skip(`${name} is not installed`);

    return null;
  }

  const page = await browser.newPage();
  const handled = await installInterruptionHandlers(page);
  await page.setContent(`<body>${html}</body>`);
  t.after(() => page.close());

  return { page, handled };
}

async function buy(page) {
  await page.locator('button.btn-buy').click({ timeout: 4000 });

  return page.evaluate(() => document.body.dataset.bought === '1');
}

for (const name of Object.keys(engines)) {
  describe(`store dialogs in front of the robot (${name})`, () => {
  it('confirms the NetzP age check that covers a Shopware product page', async (t) => {
    const setup = await pageIn(t, name, `${buyButton}<div id="netzpCheckAgeOverlay" class="netzp-app-age-check overlay" style="${cover}"><div class="buttons"><b>Sind Sie 18 Jahre alt oder älter?</b><a href="javascript:document.getElementById('netzpCheckAgeOverlay').remove(); void(0)">JA</a><a href="javascript:void(0)">NEIN</a></div></div>`);
    if (!setup) return;

    assert.equal(await buy(setup.page), true);
    assert.ok([...setup.handled].some((code) => code.startsWith('age_')), [...setup.handled].join(','));
  });

  it('declines optional cookies on the classic Shopware cookie bar', async (t) => {
    const setup = await pageIn(t, name, `${buyButton}<div class="cookie-permission-container" style="${cover}"><button class="js-cookie-permission-button" onclick="this.parentElement.remove();document.body.dataset.choice='necessary'">Nur technisch notwendige</button><button class="js-cookie-accept-all-button" onclick="this.parentElement.remove();document.body.dataset.choice='all'">Alle Cookies akzeptieren</button></div>`);
    if (!setup) return;

    assert.equal(await buy(setup.page), true);
    assert.equal(await setup.page.evaluate(() => document.body.dataset.choice), 'necessary');
  });

  it('prefers rejecting over accepting all in an unknown cookie dialog', async (t) => {
    const setup = await pageIn(t, name, `${buyButton}<div id="my-consent-layer" style="${cover}"><button onclick="this.parentElement.remove();document.body.dataset.choice='all'">Alle akzeptieren</button><button onclick="this.parentElement.remove();document.body.dataset.choice='rejected'">Alle ablehnen</button></div>`);
    if (!setup) return;

    assert.equal(await buy(setup.page), true);
    assert.equal(await setup.page.evaluate(() => document.body.dataset.choice), 'rejected');
    assert.ok(setup.handled.has('cookie_generic_reject'));
  });

  it('confirms an unknown age dialog but never answers no', async (t) => {
    const setup = await pageIn(t, name, `${buyButton}<div role="dialog" aria-modal="true" style="${cover}"><p>Sie müssen mindestens 18 Jahre alt sein.</p><button onclick="document.body.dataset.age='no'">Nein</button><button onclick="this.parentElement.remove();document.body.dataset.age='yes'">Ja, ich bin über 18</button></div>`);
    if (!setup) return;

    assert.equal(await buy(setup.page), true);
    assert.equal(await setup.page.evaluate(() => document.body.dataset.age), 'yes');
  });

  it('reports a dialog it cannot close as blocking the button, not as a failure of the worker', async (t) => {
    const setup = await pageIn(t, name, `${buyButton}<div class="newsletter-popup" style="${cover}"><p>Jetzt anmelden und 10 % sparen</p><button>Anmelden</button></div>`);
    if (!setup) return;

    const error = await setup.page.locator('button.btn-buy').click({ timeout: 1500 }).catch((caught) => caught);
    const outcome = interceptedOutcome(error);

    assert.equal(outcome?.status, 'blocked');
    assert.equal(outcome?.errorCode, 'blocking_overlay');
    assert.equal(await setup.page.evaluate(() => document.body.dataset.bought), undefined);
  });

  it('waits for a full-screen preloader to disappear', async (t) => {
    const setup = await pageIn(t, name, `${buyButton}<div id="preloader" style="${cover}"></div><script>setTimeout(() => document.getElementById('preloader').remove(), 1200)</script>`);
    if (!setup) return;

    const started = Date.now();
    await waitForPreloader(setup.page, 8000);
    const waited = Date.now() - started;

    assert.ok(waited >= 900 && waited < 6000, `waited ${waited} ms`);
    assert.equal(await setup.page.locator('#preloader').count(), 0);
  });

  it('does not wait when the page has no preloader', async (t) => {
    const setup = await pageIn(t, name, buyButton);
    if (!setup) return;

    const started = Date.now();
    await waitForPreloader(setup.page, 8000);

    assert.ok(Date.now() - started < 1000);
  });
  });
}

describe('dialog button labels', () => {
  it('recognises declining and acknowledging labels without matching accept all', () => {
    for (const label of ['Nur technisch notwendige', 'Nur notwendige Cookies akzeptieren', 'Alle ablehnen', 'Reject all', 'Only necessary cookies', 'Decline']) {
      assert.match(label, COOKIE_REJECT);
    }
    for (const label of ['Alle Cookies akzeptieren', 'Accept all', 'Konfigurieren', 'Einstellungen']) {
      assert.doesNotMatch(label, COOKIE_REJECT);
      assert.doesNotMatch(label, COOKIE_ACKNOWLEDGE);
    }
    assert.match('OK', COOKIE_ACKNOWLEDGE);
    assert.match('Verstanden', COOKIE_ACKNOWLEDGE);
  });

  it('confirms age only with a yes-like label', () => {
    for (const label of ['JA', 'Ja, ich bin über 18', 'Ich bin 18 Jahre alt', 'Yes', "I'm over 18", 'Enter']) {
      assert.match(label, AGE_CONFIRM_LABEL);
    }
    for (const label of ['NEIN', 'Nein, ich bin unter 18', 'No', 'In den Warenkorb', 'Newsletter abonnieren']) {
      assert.doesNotMatch(label, AGE_CONFIRM_LABEL);
    }
  });

  it('leaves other errors to the worker', () => {
    const timeout = Object.assign(new Error('locator.click: Timeout 20000ms exceeded.'), { name: 'TimeoutError' });

    assert.equal(interceptedOutcome(timeout), null);
    assert.equal(interceptedOutcome(new Error('boom')), null);
  });
});
