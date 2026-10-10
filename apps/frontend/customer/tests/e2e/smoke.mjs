// Browser smoke test against a running backend + Vite server. Runs inside the Playwright image:
// docker run --rm -v "$PWD/tests/e2e/smoke.mjs:/app/smoke.mjs:ro" -e BW_APP_URL=... -e BW_SMOKE_EMAIL=... -e BW_SMOKE_PASSWORD=... bw-browser-worker node smoke.mjs
import { createHmac } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.BW_APP_URL;
const email = process.env.BW_SMOKE_EMAIL;
const password = process.env.BW_SMOKE_PASSWORD;
const shots = process.env.BW_SMOKE_SCREENSHOTS ?? '/tmp';
const mailpit = process.env.BW_MAILPIT_URL;
const steps = [];
let invitationPath = null;

/** Path and query of the newest link in an email to `address` that contains `marker` (the host in emails is the backend's). */
async function mailedPath(address, marker) {
  const texts = await mailTexts(address, (all) => all.some((text) => text.includes(marker)));
  const link = texts.map((text) => (text.match(/https?:\/\/\S+/g) ?? []).find((url) => url.includes(marker))).find(Boolean);
  const url = new URL(link);

  return `${url.pathname}${url.search}`;
}

/** Main navigation: on narrow screens the links live behind the menu button. */
async function nav(page, name) {
  const menu = page.locator('button[aria-controls="mobile-menu"]');

  if (await menu.isVisible()) {
    if ((await menu.getAttribute('aria-expanded')) !== 'true') await menu.click();
    await page.locator('#mobile-menu').getByRole('link', { name, exact: true }).click();
  } else {
    await page.locator('header').getByRole('link', { name, exact: true }).click();
  }
}

/** Texts of the emails Mailpit received for an address, newest first; waits until `until` accepts them. */
async function mailTexts(address, until, timeoutMs = 30000) {
  const deadline = Date.now() + timeoutMs;

  while (Date.now() < deadline) {
    const list = await (await fetch(`${mailpit}/api/v1/messages`)).json();
    const ids = (list.messages ?? []).filter((message) => message.To.some((to) => to.Address === address)).map((message) => message.ID);
    const texts = await Promise.all(ids.map(async (id) => (await (await fetch(`${mailpit}/api/v1/message/${id}`)).json()).Text));

    if (until(texts)) return texts;

    await new Promise((resolve) => setTimeout(resolve, 1000));
  }

  throw new Error(`no matching email for ${address}`);
}

/** RFC 6238 code for a base32 secret, as an authenticator app would show it now. */
function totp(secret) {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bits = [...secret].map((char) => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const hash = createHmac('sha1', key).update(counter).digest();
  const offset = hash[19] & 0x0f;

  return String((hash.readUInt32BE(offset) & 0x7fffffff) % 1000000).padStart(6, '0');
}

const step = async (name, run) => {
  try {
    await run();
    steps.push(`ok   ${name}`);
  } catch (error) {
    steps.push(`FAIL ${name}: ${String(error.message).split('\n')[0]}`);
    await page.screenshot({ path: `${shots}/failure.png`, fullPage: true }).catch(() => undefined);
    throw error;
  }
};

const browser = await chromium.launch();
const page = await browser.newPage({ locale: 'ru-RU', viewport: { width: 1280, height: 900 } });
const consoleErrors = [];
page.on('console', (message) => message.type() === 'error' && consoleErrors.push(message.text()));
page.on('pageerror', (error) => consoleErrors.push(error.message));
const failedResponses = [];
const EXPECTED_FAILURES = [
  [401, /\/api\/v1\/auth\/me$/],
  [422, /\/api\/v1\/auth\/login$/],
  [404, /\/api\/v1\/stores\/[0-9a-f-]+\/verification$/],
];
page.on('response', (response) => {
  const url = new URL(response.url()).pathname;
  if (response.status() >= 400 && !EXPECTED_FAILURES.some(([status, pattern]) => status === response.status() && pattern.test(url))) {
    failedResponses.push(`${response.status()} ${url}`);
  }
});

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
    if ((await tone('money')) !== 'ok') throw new Error('money should be reconciling with the seeded provider');
    if ((await tone('browser_checks')) !== 'unknown') throw new Error('unverified store checks should be unknown');
    await page.screenshot({ path: `${shots}/overview-ru.png`, fullPage: true });
  });

  await step('overview gives a verdict and verified discrepancies per currency and kind', async () => {
    await page.locator('[data-verdict="problems"]').waitFor();
    await page.locator('[data-discrepancy="EUR-capture"]', { hasText: '261,00' }).waitFor();
    await page.locator('[data-tile="incidents"]').getByText('3', { exact: true }).waitFor();
  });

  await step('session survives a reload', async () => {
    await page.reload();
    await page.getByRole('heading', { name: 'Магазины' }).waitFor();
  });

  await step('the owner confirms the address from the emailed link', async () => {
    await page.locator('[data-verify-banner]').getByRole('button', { name: 'Прислать письмо ещё раз' }).click();
    await page.locator('[data-verify-banner]').getByText('Письмо отправлено.').waitFor();
    const path = await mailedPath('smoke@example.test', '/email-verification/');
    await page.goto(`${base}${path}`);
    await page.locator('[data-verified-notice]').getByText('Адрес подтверждён').waitFor();
    if ((await page.locator('[data-verify-banner]').count()) !== 0) throw new Error('banner still shown');
  });

  await step('the owner invites an operator by email', async () => {
    await page.locator('header').getByRole('link', { name: 'Smoke Owner' }).click();
    await page.getByRole('heading', { name: 'Настройки' }).waitFor();
    await page.getByRole('link', { name: 'Команда' }).click();
    await page.locator('[data-member="smoke@example.test"]').getByText('владелец').waitFor();
    await page.locator('[data-panel="invite"]').getByLabel('Электронная почта').fill('operator@smoke.example.test');
    await page.locator('[data-panel="invite"]').getByLabel('Роль').selectOption('operator');
    await page.getByRole('button', { name: 'Отправить приглашение' }).click();
    await page.locator('[data-invitation="operator@smoke.example.test"]').waitFor();
    invitationPath = await mailedPath('operator@smoke.example.test', '/app/invitation');
    await page.screenshot({ path: `${shots}/team-ru.png`, fullPage: true });
    await page.getByRole('link', { name: 'Обзор', exact: true }).click();
  });

  await step('store page shows the connector and starts DNS confirmation', async () => {
    await page.getByRole('link', { name: 'Kaffeerösterei Lindner' }).click();
    await page.waitForURL(/\/app\/stores\/[0-9a-f-]+$/);
    await page.getByRole('heading', { name: 'Kaffeerösterei Lindner' }).waitFor();
    await page.locator('[data-panel="connector"]').getByText('подключён', { exact: true }).waitFor();
    await page.locator('[data-panel="verification"]').getByText('не подтверждён').waitFor();
    await page.getByLabel('Через DNS-запись').check();
    const started = page.waitForResponse((response) => response.url().endsWith('/verify') && response.status() === 202);
    await page.getByRole('button', { name: 'Начать подтверждение' }).click();
    const challenge = (await (await started).json()).challenge;
    await page.locator('#dns-name').waitFor();
    if ((await page.locator('#dns-value').inputValue()) !== challenge) throw new Error('TXT value is not the new challenge');
    if ((await page.locator('#dns-name').inputValue()) !== '_bw-verify.kaffee-lindner.example') throw new Error('wrong DNS record name');
  });

  await step('pending DNS instructions survive a reload and a manual check explains the result', async () => {
    const value = await page.locator('#dns-value').inputValue();
    await page.reload();
    await page.locator('#dns-value').waitFor();
    if ((await page.locator('#dns-value').inputValue()) !== value) throw new Error('TXT value changed after reload');
    await page.getByRole('button', { name: 'Проверить сейчас' }).click();
    await page.locator('[data-reason]').waitFor({ timeout: 30000 });
  });

  await step('a one-time connection code is shown with the service URL', async () => {
    await page.getByRole('button', { name: /код/i }).first().click();
    await page.locator('#pairing-code').waitFor();
    if (!(await page.locator('#pairing-code').inputValue()).startsWith('bwpc_')) throw new Error('pairing code missing');
    if (!/^https?:\/\//.test(await page.locator('#pairing-service-url').inputValue())) throw new Error('service URL missing');
    await page.screenshot({ path: `${shots}/store-ru.png`, fullPage: true });
  });

  await step('a new store can be added and its settings saved', async () => {
    await page.getByRole('link', { name: '← Обзор' }).click();
    await page.getByRole('link', { name: 'Добавить магазин' }).click();
    await page.getByRole('heading', { name: 'Новый магазин' }).waitFor();
    await page.getByLabel('Название').fill('Smoke Neuer Shop');
    await page.getByLabel('Адрес магазина').fill('https://smoke-neuer-shop.example');
    await page.getByRole('button', { name: 'Добавить магазин' }).click();
    await page.getByRole('heading', { name: 'Smoke Neuer Shop' }).waitFor();
    await page.getByText('Плагин ещё не подключён.').waitFor();
    if (!(await page.getByLabel('Через плагин').isDisabled())) throw new Error('plugin method must wait for a connector');
    await page.getByLabel('Основная валюта').selectOption('USD');
    await page.getByRole('button', { name: 'Сохранить' }).click();
    await page.getByText('Сохранено.').waitFor();
    await page.getByRole('link', { name: '← Обзор' }).click();
    await page.getByRole('heading', { name: 'Магазины' }).waitFor();
  });

  await step('the incident badge leads to the store incidents', async () => {
    const card = page.locator('article', { hasText: 'Kaffeerösterei Lindner' });
    await card.getByText(/Активных инцидентов: \d+/).click();
    await page.waitForURL(/\/app\/incidents\?store=/);
    await page.getByRole('heading', { name: 'Инциденты' }).waitFor();
    await page.getByRole('link', { name: 'Оплаты подряд не проходят' }).click();
    await page.getByRole('heading', { name: 'Оплаты подряд не проходят' }).waitFor();
    await page.getByText('Способ оплаты «stripe»: 4 неудачных попыток подряд (порог 3).').waitFor();
    await page.screenshot({ path: `${shots}/incident-ru.png`, fullPage: true });
  });

  await step('comment, acknowledge, snooze and lift the snooze', async () => {
    await page.getByLabel('Комментарий для команды').fill('Smoke: <b>проверяем</b> Stripe');
    await page.getByRole('button', { name: 'Добавить комментарий' }).click();
    await page.locator('[data-activity-kind="comment"]').getByText('Smoke: <b>проверяем</b> Stripe').waitFor();
    await page.getByRole('button', { name: 'Подтвердить' }).click();
    await page.locator('[data-panel="incident-actions"]').getByText('Инцидент подтверждён').waitFor();
    await page.getByRole('button', { name: 'Заглушить уведомления' }).click();
    await page.getByLabel('Причина').fill('Smoke: known issue');
    await page.getByRole('button', { name: 'Заглушить', exact: true }).click();
    await page.locator('[data-suppression]').waitFor();
    await page.getByRole('button', { name: 'Снять заглушение' }).click();
    await page.locator('[data-suppression]').waitFor({ state: 'detached' });
  });

  await step('resolve with a reason and find it under resolved', async () => {
    await page.getByRole('button', { name: 'Закрыть', exact: true }).click();
    await page.getByLabel('Что сделано или почему закрываете').fill('Smoke: Stripe keys rotated');
    await page.getByRole('button', { name: 'Закрыть инцидент' }).click();
    await page.locator('[data-resolution]').getByText('Smoke: Stripe keys rotated').waitFor();
    await page.getByRole('link', { name: '← Инциденты' }).click();
    await page.locator('[data-incident-id]').first().waitFor();
    if ((await page.getByRole('link', { name: 'Оплаты подряд не проходят' }).count()) !== 0) throw new Error('resolved incident still listed as active');
    await page.getByRole('tab', { name: 'Закрытые' }).click();
    await page.getByRole('link', { name: 'Оплаты подряд не проходят' }).first().waitFor();
    await page.getByRole('link', { name: 'Обзор', exact: true }).click();
    await page.getByRole('heading', { name: 'Магазины' }).waitFor();
  });

  await step('reconciliation lists store-reported paid orders without a capture', async () => {
    await page.getByRole('link', { name: 'Сверка', exact: true }).click();
    await page.getByRole('heading', { name: 'Сверка денег' }).waitFor();
    if ((await page.locator('[data-coverage-warning]').count()) !== 0) throw new Error('coverage warning with a connected provider');
    const row = page.locator('tr', { hasText: '#SM-15238' });
    await row.getByText('Списание по оплаченному заказу').waitFor();
    await row.getByText('расхождение').waitFor();
    await page.screenshot({ path: `${shots}/reconciliation-ru.png`, fullPage: true });
  });

  await step('an unmatched capture is matched to an order with a reason', async () => {
    await page.getByRole('tab', { name: 'Платежи без заказа' }).click();
    const item = page.locator('[data-capture-id]', { hasText: 'ch_smoke_unmatched' });
    await item.getByText(/77,00/).first().waitFor();
    await item.getByRole('button', { name: 'Сопоставить', exact: true }).click();
    await item.locator('textarea').fill('Smoke: customer confirmed the payment');
    await item.getByRole('button', { name: 'Сопоставить с заказом' }).click();
    await page.getByText('Платёж сопоставлен').waitFor();
  });

  await step('the order reconciles again and the link can be revoked', async () => {
    await page.getByRole('tab', { name: 'Расхождения' }).click();
    await page.getByRole('link', { name: '#SM-2002' }).click();
    await page.getByRole('heading', { name: 'Заказ #SM-2002' }).waitFor();
    await page.getByRole('button', { name: 'Пересверить заказ' }).click();
    await page.locator('[data-captured]', { hasText: '77,00' }).waitFor();
    await page.locator('[data-panel="order-captures"]').getByText('вручную').waitFor();
    await page.getByRole('table').getByText('совпадает').first().waitFor();
    await page.screenshot({ path: `${shots}/order-ru.png`, fullPage: true });
    await page.getByRole('button', { name: 'Отменить связь' }).click();
    await page.getByLabel('Причина (видна в журнале)').fill('Smoke: matched by mistake');
    await page.getByRole('button', { name: 'Отменить связь' }).click();
    await page.locator('[data-panel="order-captures"]').getByText(/отменено/).waitFor();
    await page.getByRole('link', { name: 'Обзор', exact: true }).click();
    await page.getByRole('heading', { name: 'Магазины' }).waitFor();
  });

  await step('checks page shows readiness, the scenario and a failed run', async () => {
    await page.getByRole('link', { name: 'Проверки', exact: true }).click();
    await page.getByRole('heading', { name: 'Проверки оформления заказа' }).waitFor();
    await page.getByLabel('Магазин').selectOption({ label: 'Smoke Check Shop' });
    await page.locator('[data-condition="scenario"][data-met="false"]').waitFor();
    await page.locator('[data-condition="verified"][data-met="true"]').waitFor();
    await page.locator('[data-panel="runs"]').getByText('Сайт не дал дойти до формы оплаты.').waitFor();
  });

  await step('the scenario is enabled and its interval changed', async () => {
    await page.getByRole('button', { name: 'Включить проверки' }).click();
    await page.getByText('проверки работают').waitFor();
    await page.getByRole('button', { name: 'Изменить' }).click();
    await page.getByLabel('Как часто проверять').selectOption({ label: 'каждые 30 минут' });
    await page.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await page.locator('[data-panel="scenario"]').getByText('каждые 30 минут').waitFor();
    await page.screenshot({ path: `${shots}/checks-ru.png`, fullPage: true });
  });

  await step('a manual run is queued and can be cancelled', async () => {
    await page.getByRole('button', { name: 'Запустить сейчас' }).click();
    const queued = page.locator('[data-run-id]', { hasText: 'в очереди' });
    await queued.waitFor();
    await queued.getByRole('link').click();
    await page.getByText('Проверка ждёт свободный браузер.').waitFor();
    await page.getByRole('button', { name: 'Отменить проверку' }).click();
    await page.getByLabel('Причина (видна в журнале)').fill('Smoke: not needed now');
    await page.getByRole('button', { name: 'Отменить', exact: true }).click();
    await page.getByText('Проверку отменили. Это не сбой магазина.').waitFor();
  });

  await step('a failed run shows its steps and technical details', async () => {
    await page.getByRole('link', { name: '← Проверки' }).click();
    const failed = page.locator('[data-run-id]', { hasText: 'не прошла' });
    await failed.getByRole('link').click();
    await page.locator('[data-attempt="2"] [data-step="checkout"]').getByText('ошибка').waitFor();
    await page.locator('[data-attempt="1"]').getByText('Технические подробности (1)').waitFor();
    await page.screenshot({ path: `${shots}/check-run-ru.png`, fullPage: true });
    await page.getByRole('link', { name: 'Обзор', exact: true }).click();
    await page.getByRole('heading', { name: 'Магазины' }).waitFor();
  });

  await step('Stripe is connected with a restricted key and synchronised', async () => {
    await page.locator('article', { hasText: 'Smoke Check Shop' }).getByRole('link', { name: 'Smoke Check Shop' }).click();
    const panel = page.locator('[data-panel="provider"]');
    await panel.locator('[data-provider-tab="paypal"]').click();
    await panel.locator('[data-write-warning]').getByText('PayPal не умеет выдавать доступ только на чтение').waitFor();
    await panel.getByLabel('Client ID').fill('AaBbCcDdEeFfGgHhIiJjKkLlMm0123456789');
    await panel.getByLabel('Secret').fill('EeFfGgHhIiJjKkLlMmNnOoPp0123456789');
    if (await panel.getByRole('button', { name: 'Подключить PayPal' }).isEnabled()) throw new Error('PayPal must not connect before the warning is acknowledged');
    await panel.locator('[data-provider-tab="stripe"]').click();
    await panel.locator('[data-provider-tab="stripe"]').getByText('не подключён').waitFor();
    await panel.getByLabel('Ограниченный ключ Stripe').fill('sk_live_51FullSecret0123456789');
    await panel.getByText('Это полный секретный ключ').waitFor();
    if (await panel.getByRole('button', { name: 'Подключить Stripe' }).isEnabled()) throw new Error('secret key must not be accepted');
    await panel.getByLabel('Ограниченный ключ Stripe').fill('rk_test_51SmokeCheck0123456789');
    await panel.getByRole('button', { name: 'Подключить Stripe' }).click();
    await panel.getByText('rk_••••6789').waitFor();
    await panel.getByText('acct_smokeCheck').waitFor();
    await panel.getByRole('button', { name: 'Синхронизировать сейчас' }).click();
    await panel.locator('[data-last-sync]').getByText('новых событий: 2').waitFor();
    await page.screenshot({ path: `${shots}/provider-ru.png`, fullPage: true });
  });

  await step('the synchronised Stripe capture is listed among payments without an order', async () => {
    await page.locator('header').getByRole('link', { name: 'Сверка', exact: true }).click();
    await page.locator('select').filter({ has: page.locator('option', { hasText: 'Smoke Check Shop' }) }).selectOption({ label: 'Smoke Check Shop' });
    await page.getByRole('tab', { name: 'Платежи без заказа' }).click();
    for (let i = 0; i < 15 && (await page.locator('[data-capture-id]', { hasText: 'ch_check_1' }).count()) === 0; i++) {
      await page.waitForTimeout(1000);
      await page.reload();
      await page.locator('[data-panel="unmatched"]').waitFor();
    }
    await page.locator('[data-capture-id]', { hasText: 'ch_check_1' }).getByText(/42,00/).first().waitFor();
    await page.getByRole('link', { name: 'Обзор', exact: true }).click();
    await page.getByRole('heading', { name: 'Обзор', exact: true }).waitFor();
  });

  await step('an email recipient is added and confirmed with the mailed code', async () => {
    await page.getByRole('link', { name: 'Уведомления', exact: true }).click();
    await page.getByRole('heading', { name: 'Уведомления', exact: true }).waitFor();
    await page.locator('[data-empty]').waitFor();
    await page.getByRole('button', { name: 'Добавить адрес' }).click();
    await page.getByLabel('Название').fill('Дежурный');
    await page.getByLabel('Электронная почта').fill('oncall@smoke.example.test');
    await page.getByRole('button', { name: 'Отправить код' }).click();
    const card = page.locator('[data-channel-id]', { hasText: 'Дежурный' });
    await card.getByText('ждёт подтверждения').waitFor();
    const [mail] = await mailTexts('oncall@smoke.example.test', (texts) => texts.some((text) => /\b\d{6}\b/.test(text)));
    const code = /\b(\d{6})\b/.exec(mail)[1];
    await card.getByLabel('Код из письма').fill(code);
    await card.getByRole('button', { name: 'Подтвердить' }).click();
    await card.getByText('получает уведомления').waitFor();
  });

  await step('a test email is delivered by the worker and logged', async () => {
    const card = page.locator('[data-channel-id]', { hasText: 'Дежурный' });
    await card.getByRole('button', { name: 'Отправить тестовое письмо' }).click();
    await card.getByText('Тестовое письмо поставлено в очередь').waitFor();
    await mailTexts('oncall@smoke.example.test', (texts) => texts.length >= 2);
    for (let i = 0; i < 15; i++) {
      await page.reload();
      await page.locator('[data-panel="deliveries"]').waitFor();
      if ((await page.locator('[data-delivery-id]', { hasText: 'доставлено' }).count()) > 0) break;
      await page.waitForTimeout(1000);
    }
    await page.locator('[data-delivery-id]', { hasText: 'Тестовое письмо' }).getByText('доставлено').waitFor();
  });

  await step('quiet hours are saved for the recipient', async () => {
    const card = page.locator('[data-channel-id]', { hasText: 'Дежурный' });
    await card.getByRole('button', { name: 'Настройки' }).click();
    await card.getByLabel('Не присылать уведомления ночью').check();
    await card.getByRole('button', { name: 'Сохранить', exact: true }).click();
    await card.getByText(/тихие часы 22:00–07:00/).waitFor();
    await page.screenshot({ path: `${shots}/notifications-ru.png`, fullPage: true });
    await page.getByRole('link', { name: 'Обзор', exact: true }).click();
    await page.getByRole('heading', { name: 'Магазины' }).waitFor();
  });

  await step('language switch to German', async () => {
    await page.getByRole('combobox').first().selectOption('de');
    await page.getByRole('heading', { name: 'Shops' }).waitFor();
    await page.setViewportSize({ width: 375, height: 800 });
    await page.screenshot({ path: `${shots}/overview-de-mobile.png`, fullPage: true });
  });

  await step('store page in German at mobile width', async () => {
    await page.getByRole('link', { name: 'Kaffeerösterei Lindner' }).click();
    await page.getByRole('heading', { name: 'Domain-Bestätigung' }).waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflow) throw new Error('horizontal scroll on mobile');
    await page.screenshot({ path: `${shots}/store-de-mobile.png`, fullPage: true });
  });

  await step('incident page in German at mobile width', async () => {
    await nav(page, 'Vorfälle');
    await page.getByRole('heading', { name: 'Vorfälle', exact: true }).waitFor();
    await page.getByRole('tab', { name: 'Geschlossen' }).click();
    await page.getByRole('tab', { name: 'Geschlossen', selected: true }).waitFor();
    await page.waitForURL(/tab=resolved/);
    await page.getByRole('link', { name: 'Zahlungen schlagen wiederholt fehl' }).first().click();
    await page.getByRole('heading', { name: 'Was prüfen' }).waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflow) throw new Error('horizontal scroll on mobile');
    await page.screenshot({ path: `${shots}/incident-de-mobile.png`, fullPage: true });
  });

  await step('order page in German at mobile width', async () => {
    await nav(page, 'Abgleich');
    await page.getByRole('link', { name: '#SM-15238' }).click();
    await page.getByRole('heading', { name: 'Bestellung #SM-15238' }).waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflow) throw new Error('horizontal scroll on mobile');
    await page.screenshot({ path: `${shots}/order-de-mobile.png`, fullPage: true });
  });

  await step('checks page in German at mobile width', async () => {
    await nav(page, 'Prüfungen');
    await page.getByRole('heading', { name: 'Checkout-Prüfungen' }).waitFor();
    await page.getByLabel('Shop').selectOption({ label: 'Smoke Check Shop' });
    await page.getByRole('heading', { name: 'Verlauf der Prüfungen' }).waitFor();
    await page.locator('[data-panel="runs"] [data-run-id]').first().waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflow) throw new Error('horizontal scroll on mobile');
    await page.screenshot({ path: `${shots}/checks-de-mobile.png`, fullPage: true });
  });

  await step('notifications page in German at mobile width', async () => {
    await nav(page, 'Benachrichtigungen');
    await page.getByRole('heading', { name: 'Zustellprotokoll' }).waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflow) throw new Error('horizontal scroll on mobile');
    await page.screenshot({ path: `${shots}/notifications-de-mobile.png`, fullPage: true });
  });

  await step('overview in German at mobile width with the menu open', async () => {
    await nav(page, 'Übersicht');
    await page.getByRole('heading', { name: 'Übersicht', exact: true }).waitFor();
    await page.locator('button[aria-controls="mobile-menu"]').click();
    await page.locator('#mobile-menu').waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflow) throw new Error('horizontal scroll on mobile');
    await page.screenshot({ path: `${shots}/overview-de-mobile-menu.png` });
  });

  await step('sign out ends the session', async () => {
    await page.locator('#mobile-menu').getByRole('button', { name: 'Abmelden' }).click();
    await page.waitForURL(/\/app\/login/);
    await page.goto(`${base}/app/overview`);
    await page.waitForURL(/\/app\/login/);
  });

  const guestContext = await browser.newContext({ locale: 'ru-RU', viewport: { width: 1280, height: 900 } });
  const guest = await guestContext.newPage();
  guest.on('pageerror', (error) => consoleErrors.push(error.message));

  await step('the invited person creates an account from the emailed link and joins as operator', async () => {
    await guest.goto(`${base}${invitationPath}`);
    await guest.getByText('Вас приглашают в команду «Smoke GmbH» с ролью «оператор».').waitFor();
    await guest.getByLabel('Имя').fill('Smoke Operator');
    await guest.getByLabel('Новый пароль', { exact: true }).fill('operator-password-1234');
    await guest.getByLabel('Повторите новый пароль').fill('operator-password-1234');
    await guest.getByRole('button', { name: 'Создать аккаунт и присоединиться' }).click();
    await guest.waitForURL(/\/app\/overview/);
    await guest.getByRole('heading', { name: 'Обзор', exact: true }).waitFor();
    if ((await guest.getByRole('link', { name: 'Добавить магазин' }).count()) !== 0) throw new Error('operator must not add stores');
    if ((await guest.locator('[data-verify-banner]').count()) !== 0) throw new Error('invited address should count as confirmed');
  });

  await step('the operator restores a forgotten password through the emailed link', async () => {
    await guest.locator('header').getByRole('button', { name: 'Выйти' }).click();
    await guest.getByRole('link', { name: 'Забыли пароль?' }).click();
    await guest.getByRole('heading', { name: 'Сброс пароля' }).waitFor();
    await guest.getByLabel('Электронная почта').fill('operator@smoke.example.test');
    await guest.getByRole('button', { name: 'Прислать ссылку' }).click();
    await guest.locator('[data-sent]').waitFor();
    const path = await mailedPath('operator@smoke.example.test', '/app/reset-password');
    await guest.goto(`${base}${path}`);
    await guest.getByLabel('Новый пароль', { exact: true }).fill('operator-new-password-99');
    await guest.getByLabel('Повторите новый пароль').fill('operator-new-password-99');
    await guest.getByRole('button', { name: 'Сохранить пароль' }).click();
    await guest.getByText('Пароль изменён. Войдите с новым паролем.').waitFor();
    await guest.getByRole('heading', { name: 'Вход в кабинет' }).waitFor();
    await guest.getByLabel('Электронная почта').fill('operator@smoke.example.test');
    await guest.getByLabel('Пароль', { exact: true }).fill('operator-new-password-99');
    await guest.getByRole('button', { name: 'Войти' }).click();
    await guest.getByRole('heading', { name: 'Обзор', exact: true }).waitFor();
    await guestContext.close();
  });

  const ownerContext = await browser.newContext({ locale: 'ru-RU', viewport: { width: 1280, height: 900 } });
  const owner = await ownerContext.newPage();
  owner.on('pageerror', (error) => consoleErrors.push(error.message));
  let recoveryCodes = [];

  await step('the owner turns on two-factor authentication with an authenticator code', async () => {
    await owner.goto(`${base}/app/login`);
    await owner.getByLabel('Электронная почта').fill(email);
    await owner.getByLabel('Пароль', { exact: true }).fill(password);
    await owner.getByRole('button', { name: 'Войти', exact: true }).click();
    await owner.getByRole('heading', { name: 'Обзор', exact: true }).waitFor();
    await owner.goto(`${base}/app/settings/profile`);
    await owner.locator('[data-panel="mfa"]').getByRole('button', { name: 'Включить' }).click();
    await owner.locator('#mfa-password').fill(password);
    await owner.getByRole('button', { name: 'Продолжить' }).click();
    const secret = await owner.locator('#mfa-secret').inputValue();
    await owner.locator('[data-qr]').waitFor();
    await owner.locator('#mfa-code').fill(totp(secret));
    await owner.getByRole('button', { name: 'Подтвердить и включить' }).click();
    await owner.locator('[data-recovery-codes]').waitFor();
    recoveryCodes = (await owner.locator('[data-recovery-codes] li').allTextContents()).map((text) => text.trim());
    if (recoveryCodes.length !== 10) throw new Error(`expected 10 recovery codes, got ${recoveryCodes.length}`);
    await owner.screenshot({ path: `${shots}/mfa-recovery-codes.png` });
    await owner.getByRole('button', { name: 'Я сохранил коды' }).click();
    await owner.getByText('Неиспользованных резервных кодов: 10.').waitFor();
  });

  await step('signing in again asks for the second factor and accepts a recovery code', async () => {
    await owner.locator('header').getByRole('button', { name: 'Выйти' }).click();
    await owner.getByLabel('Электронная почта').fill(email);
    await owner.getByLabel('Пароль', { exact: true }).fill(password);
    await owner.getByRole('button', { name: 'Войти', exact: true }).click();
    await owner.locator('[data-step="mfa"]').waitFor();
    await owner.locator('#login-code').fill(recoveryCodes[0]);
    await owner.getByRole('button', { name: 'Войти', exact: true }).click();
    await owner.getByRole('heading', { name: 'Обзор', exact: true }).waitFor();
  });

  await step('the owner hands ownership to the operator after re-authentication', async () => {
    await owner.goto(`${base}/app/settings/team`);
    const operator = owner.locator('[data-member="operator@smoke.example.test"]');
    await operator.getByRole('button', { name: 'Передать владение' }).click();
    await operator.getByLabel('Текущий пароль').fill(password);
    await operator.getByLabel('Код из приложения или резервный код').fill(recoveryCodes[1]);
    await operator.locator('[data-transfer]').getByRole('button', { name: 'Передать владение' }).click();
    await operator.getByText('владелец').waitFor();
    await owner.locator(`[data-member="${email}"]`).getByText('администратор').waitFor();
    await ownerContext.close();
  });

  await step('no console errors', async () => {
    const relevant = consoleErrors.filter((text) => !/^Failed to load resource: the server responded with a status of \d+/.test(text));
    if (relevant.length > 0) throw new Error(relevant.join(' | '));
    if (failedResponses.length > 0) throw new Error(`unexpected failed requests: ${failedResponses.join(', ')}`);
  });
} finally {
  console.log(steps.join('\n'));
  await browser.close();
}
