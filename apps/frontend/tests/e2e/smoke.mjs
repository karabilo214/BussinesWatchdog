// Browser smoke test against a running backend + Vite server. Runs inside the Playwright image:
// docker run --rm -v "$PWD/tests/e2e/smoke.mjs:/app/smoke.mjs:ro" -e BW_APP_URL=... -e BW_SMOKE_EMAIL=... -e BW_SMOKE_PASSWORD=... bw-browser-worker node smoke.mjs
import { chromium } from 'playwright';

const base = process.env.BW_APP_URL;
const email = process.env.BW_SMOKE_EMAIL;
const password = process.env.BW_SMOKE_PASSWORD;
const shots = process.env.BW_SMOKE_SCREENSHOTS ?? '/tmp';
const steps = [];
const step = async (name, run) => {
  try {
    await run();
    steps.push(`ok   ${name}`);
  } catch (error) {
    steps.push(`FAIL ${name}: ${String(error.message).split('\n')[0]}`);
    throw error;
  }
};

const browser = await chromium.launch();
const page = await browser.newPage({ locale: 'ru-RU', viewport: { width: 1280, height: 900 } });
const consoleErrors = [];
page.on('console', (message) => message.type() === 'error' && consoleErrors.push(message.text()));
page.on('pageerror', (error) => consoleErrors.push(error.message));

try {
  await step('unauthenticated visit redirects to login', async () => {
    await page.goto(`${base}/app/overview`);
    await page.waitForURL(/\/app\/login\?redirect=/);
    await page.getByRole('heading', { name: 'Вход в кабинет' }).waitFor();
  });

  await step('wrong password shows a generic error', async () => {
    await page.getByLabel('Электронная почта').fill(email);
    await page.getByLabel('Пароль').fill('wrong-password-0000');
    await page.getByRole('button', { name: 'Войти' }).click();
    await page.getByRole('alert').filter({ hasText: 'Неверная почта или пароль.' }).waitFor();
  });

  await step('login creates a session and returns to the requested page', async () => {
    await page.getByLabel('Электронная почта').fill(email);
    await page.getByLabel('Пароль').fill(password);
    await page.getByRole('button', { name: 'Войти' }).click();
    await page.waitForURL(/\/app\/overview$/);
    await page.getByRole('heading', { name: 'Магазины' }).waitFor();
  });

  await step('store card shows coverage from the API', async () => {
    const card = page.locator('article', { hasText: 'Kaffeerösterei Lindner' });
    await card.waitFor();
    const tone = (part) => card.locator(`[data-coverage="${part}"] [data-tone]`).getAttribute('data-tone');
    if ((await tone('connector')) !== 'ok') throw new Error('connector should be ok');
    if ((await tone('money')) !== 'unknown') throw new Error('money should be unknown without a provider');
    if ((await tone('browser_checks')) !== 'unknown') throw new Error('unverified store checks should be unknown');
    await page.screenshot({ path: `${shots}/overview-ru.png`, fullPage: true });
  });

  await step('session survives a reload', async () => {
    await page.reload();
    await page.getByRole('heading', { name: 'Магазины' }).waitFor();
  });

  await step('language switch to German', async () => {
    await page.getByRole('combobox').first().selectOption('de');
    await page.getByRole('heading', { name: 'Shops' }).waitFor();
    await page.setViewportSize({ width: 375, height: 800 });
    await page.screenshot({ path: `${shots}/overview-de-mobile.png`, fullPage: true });
  });

  await step('sign out ends the session', async () => {
    await page.getByRole('button', { name: 'Abmelden' }).click();
    await page.waitForURL(/\/app\/login/);
    await page.goto(`${base}/app/overview`);
    await page.waitForURL(/\/app\/login/);
  });

  await step('no console errors', async () => {
    const relevant = consoleErrors.filter((text) => !/Failed to load resource: the server responded with a status of (401|422)/.test(text));
    if (relevant.length > 0) throw new Error(relevant.join(' | '));
  });
} finally {
  console.log(steps.join('\n'));
  await browser.close();
}
