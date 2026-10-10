// Browser smoke test against a running backend + Vite server. Runs inside the Playwright image:
// docker run --rm -v "$PWD/tests/e2e/smoke.mjs:/app/smoke.mjs:ro" -e BW_APP_URL=... -e BW_SMOKE_EMAIL=... -e BW_SMOKE_PASSWORD=... bw-browser-worker node smoke.mjs
import { chromium } from 'playwright';

const base = process.env.BW_APP_URL;
const email = process.env.BW_SMOKE_EMAIL;
const password = process.env.BW_SMOKE_PASSWORD;
const shots = process.env.BW_SMOKE_SCREENSHOTS ?? '/tmp';
const mailpit = process.env.BW_MAILPIT_URL;
const steps = [];

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

  await step('session survives a reload', async () => {
    await page.reload();
    await page.getByRole('heading', { name: 'Магазины' }).waitFor();
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
    await card.getByText('Активных инцидентов: 1').click();
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
    await page.getByRole('heading', { name: 'Активных инцидентов нет' }).waitFor();
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
    await page.getByRole('link', { name: 'Vorfälle' }).click();
    await page.getByRole('tab', { name: 'Geschlossen' }).click();
    await page.getByRole('link', { name: 'Zahlungen schlagen wiederholt fehl' }).first().click();
    await page.getByRole('heading', { name: 'Was prüfen' }).waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflow) throw new Error('horizontal scroll on mobile');
    await page.screenshot({ path: `${shots}/incident-de-mobile.png`, fullPage: true });
  });

  await step('order page in German at mobile width', async () => {
    await page.getByRole('link', { name: 'Abgleich' }).click();
    await page.getByRole('link', { name: '#SM-15238' }).click();
    await page.getByRole('heading', { name: 'Bestellung #SM-15238' }).waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflow) throw new Error('horizontal scroll on mobile');
    await page.screenshot({ path: `${shots}/order-de-mobile.png`, fullPage: true });
  });

  await step('checks page in German at mobile width', async () => {
    await page.getByRole('link', { name: 'Prüfungen' }).click();
    await page.getByRole('heading', { name: 'Checkout-Prüfungen' }).waitFor();
    await page.getByLabel('Shop').selectOption({ label: 'Smoke Check Shop' });
    await page.getByRole('heading', { name: 'Verlauf der Prüfungen' }).waitFor();
    await page.locator('[data-panel="runs"] [data-run-id]').first().waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflow) throw new Error('horizontal scroll on mobile');
    await page.screenshot({ path: `${shots}/checks-de-mobile.png`, fullPage: true });
  });

  await step('notifications page in German at mobile width', async () => {
    await page.getByRole('link', { name: 'Benachrichtigungen' }).click();
    await page.getByRole('heading', { name: 'Zustellprotokoll' }).waitFor();
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth);
    if (overflow) throw new Error('horizontal scroll on mobile');
    await page.screenshot({ path: `${shots}/notifications-de-mobile.png`, fullPage: true });
  });

  await step('sign out ends the session', async () => {
    await page.getByRole('button', { name: 'Abmelden' }).click();
    await page.waitForURL(/\/app\/login/);
    await page.goto(`${base}/app/overview`);
    await page.waitForURL(/\/app\/login/);
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
