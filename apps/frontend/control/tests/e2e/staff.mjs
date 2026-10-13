import { createHmac } from 'node:crypto';
import { chromium } from 'playwright';

const base = process.env.BW_CONTROL_URL;
const site = process.env.BW_SITE_URL;
const shots = process.env.BW_SHOTS ?? '/tmp';

function code(secret, offset = 0) {
  const step = Math.floor(Date.now() / 30000) + offset;
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
  await target.screenshot({ path: `${shots}/staff-${name}.png`, fullPage: true });
}

const invitee = `staff-${Date.now().toString(36)}@example.test`;

async function signIn(target, email, password, secret, fresh = false) {
  await target.goto(`${base}/owner/login`);
  await target.getByLabel('Электронная почта').fill(email);
  await target.getByLabel('Пароль').fill(password);
  await target.getByRole('button', { name: 'Продолжить' }).click();
  await target.getByLabel('Код', { exact: true }).fill(fresh ? await nextCode(secret) : code(secret));
  await target.getByRole('button', { name: 'Войти' }).click();
  await target.locator('nav').waitFor();
}

async function nextCode(secret) {
  const used = code(secret);
  while (code(secret) === used) await new Promise((resolve) => setTimeout(resolve, 1000));

  return code(secret);
}

try {
  await signIn(page, process.env.BW_EMAIL, process.env.BW_PASSWORD, process.env.BW_TOTP_SECRET);
  await page.goto(`${base}/owner/staff`);
  await page.locator('[data-action="invite"]').click();
  await page.locator('[data-field="email"]').fill(invitee);
  await page.locator('[data-field="role"]').selectOption('platform_support');
  await page.locator('[data-field="reason"]').fill('Live check');
  await page.locator('[data-field="code"]').fill(await nextCode(process.env.BW_TOTP_SECRET));
  await page.locator('[data-action="send-invite"]').click();
  await page.locator(`[data-invitation="${invitee}"]`).waitFor();
  await shot(page, '1-invited');
  const mails = await (await fetch(`${process.env.BW_MAILPIT_URL}/api/v1/messages?limit=50`)).json();
  const mail = mails.messages.find((message) => message.To.some((to) => to.Address === invitee));
  if (!mail) throw new Error('invitation email not found in Mailpit');
  const full = await (await fetch(`${process.env.BW_MAILPIT_URL}/api/v1/message/${mail.ID}`)).json();
  const token = /staff-invitation\?token=([A-Za-z0-9]+)/.exec(full.Text)?.[1];
  if (!token) throw new Error('no token in the invitation email');
  console.log('ok   staff member invited with the authenticator code; email with the link in Mailpit');

  const joinContext = await browser.newContext({ locale: 'ru-RU', viewport: { width: 1280, height: 900 } });
  const join = await joinContext.newPage();
  await join.goto(`${base}/owner/staff-invitation?token=${token}`);
  await join.locator('[data-field="name"]').fill('Live Support');
  await join.locator('[data-field="password"]').fill('support-password-1234');
  await join.locator('[data-field="repeat"]').fill('support-password-1234');
  await join.locator('[data-action="continue"]').click();
  await join.locator('[data-qr] svg').waitFor();
  const secret = (await join.locator('[data-secret]').innerText()).trim();
  await shot(join, '2-authenticator');
  await join.locator('[data-field="code"]').fill(code(secret));
  await join.locator('[data-action="join"]').click();
  await join.locator('[data-recovery]').waitFor();
  console.log('ok   invitation accepted: password, authenticator from the QR secret, recovery codes shown');

  await signIn(join, invitee, 'support-password-1234', secret, true);
  await join.goto(`${base}/owner/staff`);
  await join.getByText('Раздел доступен владельцу сервиса.').waitFor();
  if ((await join.locator('[data-action="invite"]').count()) !== 0) throw new Error('support must not manage staff');
  await join.goto(`${base}/owner/contacts`);
  await join.getByRole('heading', { level: 1 }).waitFor();
  console.log('ok   new support member signs in with the new authenticator');

  await page.reload();
  await page.locator(`[data-staff="${invitee}"] [data-action="disable"]`).click();
  await page.locator('[data-field="action-reason"]').fill('Live check done');
  await page.locator('[data-field="action-code"]').fill(await nextCode(process.env.BW_TOTP_SECRET));
  await page.locator('[data-action="confirm"]').click();
  await page.locator('[data-notice]').getByText('Сотрудник отключён.').waitFor();
  await shot(page, '3-disabled');
  await join.goto(`${base}/owner/contacts`);
  await join.waitForURL(/\/owner\/login/);
  console.log('ok   member disabled with the authenticator code; their session ended at once');

  await page.goto(`${base}/owner/audit`);
  await page.locator('[data-entry]').first().waitFor();
  await page.locator('[data-field="action"]').selectOption('staff.updated');
  await page.locator('[data-action="apply"]').click();
  await page.waitForURL(/action=staff\.updated/);
  await page.locator('[data-entry="staff.updated"]').getByText('Live check done').first().waitFor();
  await shot(page, '4-audit');
  const csv = await page.request.get(`${base}${await page.locator('[data-action="export"]').getAttribute('href')}`);
  if (!csv.ok() || !(await csv.text()).includes('staff.updated')) throw new Error('audit export failed');
  console.log('ok   audit filtered by action shows the disabling with its reason; CSV export of the same filter');

  if (errors.length > 0) throw new Error(`page errors: ${errors.join('; ')}`);
  console.log('PASS');
} catch (error) {
  await shot(page, 'failure').catch(() => undefined);
  console.log(`FAIL ${String(error.message).split('\n')[0]}`);
  process.exitCode = 1;
} finally {
  await browser.close();
}
