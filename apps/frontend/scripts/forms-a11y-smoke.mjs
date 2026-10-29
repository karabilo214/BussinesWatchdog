// Run only against the isolated local forms fixture (docs/ops/forms-accessibility.md).
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { chromium, firefox, webkit } from '../../browser-worker/node_modules/playwright/index.mjs';
import AxeBuilder from '@axe-core/playwright';
const base = process.env.BW_FORMS_URL ?? 'https://localhost:8443';
assert.equal(new URL(base).hostname, 'localhost', 'Synthetic registration/contact check is local only');
const fixture = execFileSync('docker', ['exec', 'bw-forms-backend', 'php', 'tests/Browser/forms-fixture.php'], { encoding: 'utf8' });
assert.match(fixture, /Synthetic forms fixture ready/);
const results = [];
const password = 'Local-forms-test-12345';
const filled = async (page, selector, value) => page.locator(selector).fill(value);
async function focused(page, selector) { try { await page.waitForFunction((s) => document.activeElement === document.querySelector(s), selector, { timeout: 10000 }); } catch (error) { console.error({ url: page.url(), selector, active: await page.evaluate(() => document.activeElement?.outerHTML.slice(0, 300)), text: (await page.locator('body').innerText()).slice(0, 1600) }); throw error; } }
async function letterTo(email) {
  const inbox = await (await fetch('http://localhost:8025/api/v1/messages')).json();
  const message = inbox.messages.find((entry) => entry.To.some((recipient) => recipient.Address === email));
  assert.ok(message, 'Registration email delivered to local Mailpit');
  return await (await fetch(`http://localhost:8025/api/v1/message/${message.ID}`)).json();
}
async function audit(page, label) {
  await page.evaluate(() => document.fonts.ready);
  const { violations } = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
  assert.deepEqual(violations.map(({ id, nodes }) => ({ id, targets: nodes.map((n) => n.target) })), [], label);
  const dangling = await page.locator('[aria-describedby]').evaluateAll((nodes) => nodes.flatMap((node) => node.getAttribute('aria-describedby').trim().split(/\s+/).filter((id) => !document.getElementById(id))));
  assert.deepEqual(dangling, [], label + ' description references');
  assert.deepEqual(await page.evaluate(() => window.__violations), [], label + ' CSP');
  results.push(label);
  console.log('PASS', label);
}
for (const [engine, type] of Object.entries({ chromium, firefox, webkit })) {
  const browser = engine === 'webkit' && process.env.BW_FORMS_WEBKIT_WS
    ? await type.connect(process.env.BW_FORMS_WEBKIT_WS, { exposeNetwork: '<loopback>' })
    : await type.launch();
  try {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, locale: 'en-US' });
    await context.addInitScript(() => {
      window.__violations = [];
      document.addEventListener('securitypolicyviolation', (event) => { if (event.disposition === 'enforce') window.__violations.push(event.effectiveDirective); });
    });
    const page = await context.newPage();
    for (const locale of ['en', 'de', 'ru']) {
      for (const path of [`/${locale}/signup`, `/${locale}/contact`, '/app/login', '/owner/login']) {
        if (path.startsWith('/app') || path.startsWith('/owner')) await page.evaluate((value) => { localStorage.setItem('bw.locale', value); localStorage.setItem('bw.control.locale', value); }, locale);
        const response = await page.goto(base + path);
        assert.ok([200, 304].includes(response.status()), path + ' status ' + response.status());
        await page.locator('form input[type=password], form input[name=name]').first().waitFor();
        assert.equal(await page.locator('html').getAttribute('lang'), locale, path + ' language');
        await page.locator('input[name=email]').focus();
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement?.getAttribute('name')), path.endsWith('/contact') ? 'company' : 'password', path + ' keyboard tab order');
        assert.equal(await page.evaluate(() => getComputedStyle(document.activeElement).outlineStyle), 'solid', path + ' visible keyboard focus');
        await audit(page, `${engine} ${locale} ${path}`);
        await page.setViewportSize({ width: 320, height: 740 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), path + ' 320px reflow');
        if (engine === 'chromium' && locale === 'en') await page.screenshot({ path: `/private/tmp/bw-forms-${path.replaceAll('/', '-')}-mobile.png`, fullPage: true });
        await page.setViewportSize({ width: 1280, height: 900 });
        if (path.endsWith('/login')) {
          await page.locator('button[type=submit]').click();
          await focused(page, '#auth-errors');
          await audit(page, `${engine} ${locale} ${path} empty errors`);
          await page.locator('#auth-errors a').first().click();
          await focused(page, 'input[name=email]');
        }
      }
    }
    // Actual server rejection, preserving non-secret fields and focusing actionable errors.
    await page.goto(base + '/en/contact');
    await filled(page, '#contact-name', 'Synthetic Browser');
    await filled(page, '#contact-email', 'forms-contact@example.test');
    await filled(page, '#contact-message', `Synthetic ${engine} local accessibility check.`);
    await page.locator('[data-captcha-text]').click();
    await page.locator('#captcha-text').waitFor({ state: 'visible' });
    const numbers = (await page.locator('#captcha-text').innerText()).match(/\d+/g).map(Number);
    assert.equal(numbers.length, 2);
    await filled(page, '#contact-captcha', '99');
    await page.locator('button[type=submit]').click();
    await focused(page, '#form-errors');
    assert.equal(await page.locator('#contact-name').inputValue(), 'Synthetic Browser');
    assert.equal(await page.locator('#contact-captcha').getAttribute('aria-invalid'), 'true');
    await audit(page, `${engine} contact server error`);
    await page.locator('#form-errors a').click();
    await focused(page, '#contact-captcha');
    await page.locator('[data-captcha-text]').click();
    await page.locator('#captcha-text').waitFor({ state: 'visible' });
    const fresh = (await page.locator('#captcha-text').innerText()).match(/\d+/g).map(Number);
    await filled(page, '#contact-captcha', String(fresh[0] + fresh[1]));
    await page.locator('#contact-attachment').setInputFiles({ name: 'oversized.pdf', mimeType: 'application/pdf', buffer: Buffer.alloc(5 * 1024 * 1024 + 1) });
    await page.locator('button[type=submit]').click();
    assert.equal(await page.locator('#contact-attachment').evaluate((input) => input.validity.customError), true);
    await focused(page, '#contact-attachment');
    await page.locator('#contact-attachment').setInputFiles({ name: 'synthetic.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n') });
    await page.locator('button[type=submit]').click();
    await focused(page, '[data-contact=sent]');
    await audit(page, `${engine} contact saved with PDF`);
    // Real registration, password confirmation and native autofill (no input/change event).
    const email = `forms-${engine}-${Date.now()}@example.test`;
    await page.goto(base + '/en/signup');
    await filled(page, '#signup-last_name', 'Synthetic Browser');
    await filled(page, '#signup-organization_name', `Synthetic ${engine}`);
    await filled(page, '#signup-email', email);
    await filled(page, '#signup-password', password);
    await page.locator('[data-toggle-password=signup-password]').click();
    assert.equal(await page.locator('#signup-password').getAttribute('type'), 'text');
    await page.locator('[data-toggle-password=signup-password]').click();
    await filled(page, '#signup-password_confirmation', 'Different-password-123');
    await page.locator('button[type=submit]').click();
    assert.equal(await page.locator('#signup-password_confirmation').evaluate((e) => e.validity.customError), true);
    await filled(page, '#signup-password_confirmation', password);
    await page.locator('#signup-accept').check();
    await page.locator('button[type=submit]').click();
    await page.waitForURL('**/app/settings/billing');
    results.push(`${engine} registration persisted and authenticated`);
    const letter = await letterTo(email);
    const verification = letter.Text.match(/https:\/\/localhost:8443\/api\/v1\/auth\/email-verification\/[^\s<>]+/)?.[0];
    assert.ok(verification, 'Local signed email verification link');
    await page.goto(verification);
    await page.waitForURL('**/app/**');
    results.push(`${engine} registration email delivered and address verified`);
    await context.clearCookies();
    await page.goto(base + '/app/login');
    await page.locator('input[name=password]').waitFor();
    await page.locator('form').evaluate((form, values) => { form.elements.email.value = values.email; form.elements.password.value = values.password; }, { email, password });
    await page.locator('button[type=submit]').click();
    await page.waitForURL('**/app/overview');
    results.push(`${engine} real customer sign-in with native autofill`);
    await context.clearCookies();
    await page.goto(base + '/owner/login');
    await page.locator('input[name=password]').waitFor();
    await filled(page, 'input[name=email]', 'forms-staff@example.test');
    await filled(page, 'input[name=password]', password);
    await page.locator('button[type=submit]').click();
    await focused(page, 'input[name=code]');
    await audit(page, `${engine} staff MFA`);
    const code = execFileSync('docker', ['exec', 'bw-forms-backend', 'php', 'tests/Browser/forms-fixture.php', 'code'], { encoding: 'utf8' }).trim();
    assert.match(code, /^\d{6}$/);
    await filled(page, 'input[name=code]', code === '000000' ? '000001' : '000000');
    await page.locator('button[type=submit]').click();
    await focused(page, '#auth-errors');
    await audit(page, `${engine} staff MFA rejected code`);
    await page.locator('input[name=code]').evaluate((input, value) => { input.value = value; }, code);
    await page.locator('button[type=submit]').click();
    await page.waitForURL('**/owner');
    results.push(`${engine} real staff MFA with native code autofill`);
    await context.close();
    // Server validation and text CAPTCHA remain usable with JavaScript disabled.
    const nojs = await browser.newContext({ ignoreHTTPSErrors: true, javaScriptEnabled: false });
    const plain = await nojs.newPage();
    await plain.goto(base + '/en/contact');
    await filled(plain, '#contact-name', 'Synthetic no JS');
    await filled(plain, '#contact-email', 'forms-nojs@example.test');
    await filled(plain, '#contact-message', 'Synthetic local form without JavaScript.');
    const challenge = await nojs.request.get(base + '/en/contact/captcha/text');
    const operands = (await challenge.text()).match(/\d+/g).map(Number);
    await filled(plain, '#contact-captcha', String(operands[0] + operands[1]));
    await plain.locator('button[type=submit]').click();
    await plain.locator('[data-contact=sent]').waitFor();
    results.push(`${engine} contact works without JavaScript`);
    await plain.goto(base + '/en/signup');
    await filled(plain, '#signup-last_name', 'Synthetic no JS');
    await filled(plain, '#signup-organization_name', 'Synthetic no JS');
    await filled(plain, '#signup-email', 'forms-staff@example.test');
    await filled(plain, '#signup-password', password);
    await filled(plain, '#signup-password_confirmation', password);
    await plain.locator('#signup-accept').check();
    await plain.locator('button[type=submit]').click();
    await plain.locator('#form-errors').waitFor();
    assert.equal(await plain.locator('#signup-password').inputValue(), '');
    assert.equal(await plain.locator('#signup-email').getAttribute('aria-invalid'), 'true');
    await plain.locator('#form-errors a').click();
    await focused(plain, '#signup-email');
    const nojsEmail = `forms-nojs-${engine}-${Date.now()}@example.test`;
    await filled(plain, '#signup-email', nojsEmail);
    await filled(plain, '#signup-password', password);
    await filled(plain, '#signup-password_confirmation', password);
    await plain.locator('#signup-accept').check();
    await plain.locator('button[type=submit]').click();
    await plain.waitForURL('**/app/settings/billing');
    await letterTo(nojsEmail);
    results.push(`${engine} registration validation and submission without JavaScript`);
    await nojs.close();
  } finally { await browser.close(); }
}
const delivery = execFileSync('docker', ['exec', 'bw-forms-backend', 'php', 'artisan', 'site:contact-notify'], { encoding: 'utf8' });
assert.match(delivery, /sent=[1-9]\d* failed=0/);
const notification = await letterTo('forms-staff@example.test');
assert.match(notification.Text, /Synthetic/);
results.push('Contact notifications delivered to local Mailpit');
console.log(JSON.stringify({ passed: results.length, results }, null, 2));
