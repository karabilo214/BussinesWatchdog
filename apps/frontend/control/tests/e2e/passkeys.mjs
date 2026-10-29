import assert from 'node:assert/strict';
import { chromium } from 'playwright';

// Use an isolated, disposable staff fixture. Codes are consumed once and never logged.
const base = process.env.BW_CONTROL_URL;
const email = process.env.BW_EMAIL;
const password = process.env.BW_PASSWORD;
const recovery = (process.env.BW_RECOVERY_CODES ?? '').split(',');
assert(base && email && password && recovery.length >= 3, 'Provide BW_CONTROL_URL, BW_EMAIL, BW_PASSWORD and three BW_RECOVERY_CODES.');
const browser = await chromium.launch();
const context = await browser.newContext({ locale: 'ru-RU', viewport: { width: 1280, height: 900 } });
const page = await context.newPage();
const errors = [];
page.on('pageerror', error => errors.push(error.message));
const cdp = await context.newCDPSession(page);
await cdp.send('WebAuthn.enable');
const { authenticatorId } = await cdp.send('WebAuthn.addVirtualAuthenticator', { options: { protocol: 'ctap2', transport: 'internal', hasResidentKey: true, hasUserVerification: true, isUserVerified: true, automaticPresenceSimulation: true } });
try {
  await page.goto(`${base}/owner/login?redirect=/owner/profile`);
  await page.locator('#staff-email').fill(email);
  await page.locator('#staff-password').fill(password);
  const passwordResponse = page.waitForResponse(r => r.url().endsWith('/auth/login') && r.request().method() === 'POST');
  await page.locator('button[type="submit"]').click();
  const passwordResult = await passwordResponse;
  assert.equal(passwordResult.status(), 200, `Password login failed: ${await passwordResult.text()}`);
  await page.locator('#staff-code').fill(recovery[0]);
  await page.locator('[data-step="mfa"] button[type="submit"]').click();
  await page.waitForURL(/\/owner\/profile$/);
  await page.locator('#passkey-name').fill('Virtual Touch ID');
  await page.locator('#passkey-password').fill(password);
  await page.locator('#passkey-code').fill(recovery[1]);
  const registered = page.waitForResponse(r => r.url().endsWith('/me/passkeys/register') && r.request().method() === 'POST');
  await page.locator('[data-panel="passkeys"] button[type="submit"]').click();
  const response = await registered;
  assert.equal(response.status(), 201, `Registration failed: ${await response.text()}`);
  const key = await response.json();
  assert.equal(key.name, 'Virtual Touch ID');
  assert(!('credential' in key) && !('credential_id' in key));
  await page.locator('[data-panel="passkeys"] li').filter({ hasText: 'Virtual Touch ID' }).waitFor();
  assert.equal((await cdp.send('WebAuthn.getCredentials', { authenticatorId })).credentials.length, 1);
  console.log('ok   browser registered a resident platform credential with user verification');

  const cookies = (await context.cookies()).map(c => c.name);
  assert(cookies.includes('bw-platform-session') && cookies.includes('BW-PLATFORM-XSRF'));
  assert(!cookies.includes('XSRF-TOKEN'));
  if (process.env.BW_SHOTS) {
    await page.screenshot({ path: `${process.env.BW_SHOTS}/passkeys-desktop.png`, fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.screenshot({ path: `${process.env.BW_SHOTS}/passkeys-mobile.png`, fullPage: true });
    assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
    await page.setViewportSize({ width: 1280, height: 900 });
  }
  await page.getByRole('button', { name: 'Выйти', exact: true }).click();
  await page.waitForURL(/\/owner\/login/);
  await page.goto(`${base}/owner/login?redirect=/owner/profile`);
  const loggedIn = page.waitForResponse(r => r.url().endsWith('/auth/passkeys/login') && r.request().method() === 'POST');
  await page.locator('[data-passkey-login]').click();
  assert.equal((await loggedIn).status(), 200);
  await page.waitForURL(/\/owner\/profile$/);
  console.log('ok   the same key signs into the platform after logout, using its own cookies');

  await page.locator('#passkey-password').fill(password);
  await page.locator('#passkey-code').fill(recovery[2]);
  const row = page.locator('[data-panel="passkeys"] li').filter({ hasText: 'Virtual Touch ID' });
  await row.getByRole('button', { name: 'Удалить', exact: true }).click();
  const deleted = page.waitForResponse(r => r.url().endsWith(`/me/passkeys/${key.id}`) && r.request().method() === 'DELETE');
  await row.getByRole('button', { name: 'Подтвердить удаление', exact: true }).click();
  assert.equal((await deleted).status(), 204);
  await page.getByRole('button', { name: 'Выйти', exact: true }).click();
  await page.waitForURL(/\/owner\/login/);
  const denied = page.waitForResponse(r => r.url().endsWith('/auth/passkeys/login') && r.request().method() === 'POST');
  await page.locator('[data-passkey-login]').click();
  assert.equal((await denied).status(), 422);
  await page.getByRole('alert').waitFor();
  assert(await page.locator('#staff-password').isVisible());
  assert.equal(errors.length, 0, errors.join('; '));
  console.log('ok   revoked browser credential is refused; password and MFA remain available');
  console.log('PASS');
} finally {
  await browser.close();
}
