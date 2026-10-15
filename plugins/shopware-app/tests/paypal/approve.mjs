import { chromium } from 'playwright';

const browser = await chromium.launch();
const page = await (await browser.newContext({ locale: 'en-US', viewport: { width: 1280, height: 1000 } })).newPage();

try {
  await page.goto(process.env.BW_APPROVE_URL, { waitUntil: 'domcontentloaded', timeout: 90000 });
  const email = page.locator('#email');
  await email.waitFor({ timeout: 90000 });
  await email.fill(process.env.BUYER_EMAIL);
  const password = page.getByLabel('Password', { exact: true });
  const next = page.getByRole('button', { name: 'Next', exact: true });
  if (!(await password.isVisible()) && (await next.count()) > 0) await next.click();
  await password.waitFor({ state: 'visible', timeout: 60000 });
  await page.waitForTimeout(1500);
  await password.click();
  await password.pressSequentially(process.env.BUYER_PASSWORD, { delay: 30 });
  await page.getByRole('button', { name: /^Log ?In$/i }).first().click();
  const submit = page.getByRole('button', { name: /^(Pay|Pay Now|Complete Purchase|Continue to Review Order|Continue|Agree & Continue|Save and Continue)$/ }).first();
  await submit.waitFor({ timeout: 90000 });
  await page.waitForTimeout(2000);
  await submit.click();
  await page.waitForURL((url) => url.hostname === process.env.BW_SHOP_HOST, { timeout: 120000 });
  await page.waitForLoadState('domcontentloaded');
  console.log('BW_RETURNED=' + new URL(page.url()).pathname);
} catch (error) {
  console.log('FAILED', error?.name ?? 'approval_error');
  process.exitCode = 1;
} finally {
  await browser.close();
}
