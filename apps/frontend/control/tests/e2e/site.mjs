import { createHmac } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.BW_CONTROL_URL;
const site = process.env.BW_SITE_URL;
const shots = process.env.BW_SHOTS ?? '/tmp';
const slug = `about-${Date.now().toString(36)}`;
const visitor = `visitor-${Date.now().toString(36)}@example.test`;

function code(secret) {
  const step = Math.floor(Date.now() / 30000);
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const bits = [...secret].map((char) => alphabet.indexOf(char).toString(2).padStart(5, '0')).join('');
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(step));
  const hash = createHmac('sha1', key).update(counter).digest();
  const at = hash[19] & 0x0f;

  return String((hash.readUInt32BE(at) & 0x7fffffff) % 1000000).padStart(6, '0');
}

const browser = await chromium.launch();
const context = await browser.newContext({ locale: 'ru-RU', viewport: { width: 1280, height: 900 } });
const page = await context.newPage();
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));

async function shot(target, name) {
  await target.screenshot({ path: `${shots}/site-${name}.png`, fullPage: true });
}

try {
  await page.goto(`${base}/owner/site`);
  await page.getByLabel('Электронная почта').fill(process.env.BW_EMAIL);
  await page.getByLabel('Пароль').fill(process.env.BW_PASSWORD);
  await page.getByRole('button', { name: 'Продолжить' }).click();
  await page.getByLabel('Код', { exact: true }).fill(code(process.env.BW_TOTP_SECRET));
  await page.getByRole('button', { name: 'Войти' }).click();
  await page.waitForURL(/\/owner\/site$/);
  for (const path of ['/', 'pricing', 'contact']) await page.locator(`[data-page="${path}"]`).waitFor();
  console.log('ok   site section lists the home, pricing and contact pages');

  await page.locator('[data-page="/"] a').click();
  await page.locator('[data-visual]').getByText('Узнайте, что оплата сломалась').waitFor();
  await page.locator('[data-visual] section.hero a.button').first().waitFor();
  await shot(page, '0-home-editor');
  await page.goto(`${base}/owner/site`);
  await page.locator('[data-page="pricing"] a').click();
  await page.locator('[data-in-code]').waitFor();
  if ((await page.locator('[data-form="page-draft"]').count()) !== 0) throw new Error('pricing must not have an editor');
  await page.goto(`${base}/owner/site`);
  await page.locator('[data-field="slug"]').waitFor();
  console.log('ok   home opens in the visual editor with its built-in text; pricing is edited in code');

  await page.locator('[data-field="slug"]').fill(slug);
  await page.locator('[data-field="menu"]').first().selectOption('header');
  await page.locator('[data-action="create"]').click();
  await page.waitForURL(/\/owner\/site\/pages\//);
  await page.locator('[data-field="title"]').fill('О компании');
  await page.locator('[data-field="description"]').fill('Кто делает Business Watchdog');
  await page.locator('[data-visual]').click();
  await page.keyboard.type('Мы пишем текст без HTML.');
  await page.locator('[data-tool="text-style"]').selectOption('lead');
  await page.locator('[data-tool="section"]').click();
  await page.locator('[data-tool="section-style"]').selectOption('card');
  await page.locator('[data-visual] section.card p.lead').getByText('Мы пишем текст без HTML.').waitFor();
  await page.frameLocator('[data-preview]').locator('section.card p.lead').getByText('Мы пишем текст без HTML.').waitFor();
  await shot(page, '0-visual-editor');
  console.log('ok   visual editor: typed text styled as a lead paragraph inside a card, shown in the preview');

  await page.locator('[data-mode="html"]').click();
  if (!(await page.locator('[data-field="body"]').inputValue()).includes('<section class="card"><p class="lead">Мы пишем текст без HTML.</p></section>')) throw new Error('visual edit did not reach the HTML');
  await page.locator('[data-field="body"]').fill('<p class="lead">Мы следим за оплатой в интернет-магазинах.</p><div class="grid-2"><div class="card"><h3>Честно</h3><p>Нет данных — так и пишем.</p></div><div class="card"><h3>Безопасно</h3><p>Без настоящих покупок.</p></div></div><script>window.hacked = 1</script><p onclick="window.hacked = 2">Клик</p>');
  await page.frameLocator('[data-preview]').getByText('Мы следим за оплатой').waitFor();
  await page.locator('[data-mode="visual"]').click();
  await page.locator('[data-visual] .grid-2 .card h3').first().waitFor();
  await shot(page, '1-editor');
  console.log('ok   draft shown in the sandboxed preview while typing');

  await page.locator('[data-action="save"]').click();
  await page.locator('[data-notice]').getByText('Черновик сохранён.').waitFor();
  await page.locator('[data-action="publish"]').click();
  await page.locator('[data-field="reason"]').fill('Первая версия страницы');
  await page.locator('[data-action="confirm"]').click();
  await page.locator('[data-notice]').getByText('Опубликовано.').waitFor();
  await page.locator('[data-history] [data-version="1"]').waitFor();
  console.log(`ok   page /ru/${slug} saved and published with a reason, version 1 in the history`);

  const visitorPage = await (await browser.newContext({ locale: 'ru-RU', viewport: { width: 390, height: 844 }, javaScriptEnabled: false })).newPage();
  const response = await visitorPage.goto(`${site}/ru/${slug}`);
  if (response?.status() !== 200) throw new Error(`public page answered ${response?.status()}`);
  const html = await response.text();
  if (!html.includes('<h1>О компании</h1>') || !html.includes('class="grid-2"')) throw new Error('public page is not rendered on the server');
  if (html.includes('window.hacked') || html.includes('onclick')) throw new Error('script or handler reached the public page');
  if (!html.includes('rel="stylesheet" href="/site/assets/')) throw new Error('site stylesheet is not linked');
  await visitorPage.getByText('Меню').click();
  await visitorPage.locator('.site-menu').getByRole('link', { name: 'О компании' }).waitFor();
  await shot(visitorPage, '2-public-mobile');
  console.log('ok   public page served as complete HTML without JavaScript, script and handler removed, linked in the menu');

  if ((await visitorPage.goto(`${site}/en/${slug}`))?.status() !== 404) throw new Error('untranslated page must be 404');
  const pricing = await visitorPage.goto(`${site}/ru/pricing`);
  if (pricing?.status() !== 200) throw new Error(`pricing answered ${pricing?.status()}`);
  await visitorPage.getByRole('heading', { level: 1, name: 'Тарифы' }).waitFor();
  await shot(visitorPage, '3-pricing');
  console.log('ok   English version is 404 until translated; pricing page served');

  const form = await (await browser.newContext({ locale: 'ru-RU', viewport: { width: 1280, height: 900 } })).newPage();
  await form.goto(`${site}/ru/pricing`);
  await shot(form, '3-pricing-desktop');
  await form.goto(`${site}/ru/contact`);
  await form.getByRole('button', { name: 'Отправить' }).click();
  await form.locator('.site-errors').waitFor();
  await form.getByLabel('Ваше имя').fill('Live Visitor');
  await form.getByLabel('E-mail для ответа').fill(visitor);
  await form.getByLabel('Сообщение').fill('Хочу узнать про подключение двух магазинов.');
  await form.getByRole('button', { name: 'Отправить' }).click();
  await form.locator('[data-contact="sent"]').waitFor();
  await shot(form, '4-contact-sent');
  console.log('ok   contact form: errors shown for an empty form, message sent');

  await page.goto(`${base}/owner/contacts`);
  const row = page.locator(`[data-contact="${visitor}"]`);
  await row.waitFor();
  await row.getByRole('link', { name: 'Live Visitor' }).click();
  await page.locator('[data-message]').getByText('двух магазинов').waitFor();
  await page.locator('[data-field="status"]').selectOption('closed');
  await page.locator('[data-field="note"]').fill('Ответили письмом');
  await page.locator('[data-action="save"]').click();
  await page.locator('[data-saved]').waitFor();
  await shot(page, '5-contact');
  console.log('ok   message visible in the owner panel and closed with a note');

  await page.goBack();
  await page.goto(`${base}/owner/site`);
  await page.locator(`[data-page="${slug}"] a`).click();
  await page.locator('[data-action="archive"]').click();
  await page.locator('[data-field="reason"]').fill('Убираем после проверки');
  await page.locator('[data-action="confirm"]').click();
  await page.waitForURL(/\/owner\/site$/);
  const fresh = await (await browser.newContext({ javaScriptEnabled: false })).newPage();
  if ((await fresh.goto(`${site}/ru/${slug}`))?.status() !== 404) throw new Error('archived page must be 404');
  console.log('ok   page deleted from the site, its address answers 404');

  if (errors.length > 0) throw new Error(`page errors: ${errors.join('; ')}`);
  console.log('PASS');
} catch (error) {
  await shot(page, 'failure').catch(() => undefined);
  console.log(`FAIL ${String(error.message).split('\n')[0]}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
