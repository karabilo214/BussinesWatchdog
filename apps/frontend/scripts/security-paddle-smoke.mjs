// Real Paddle network/SDK, without a payment submission. API fixtures isolate our account UI only.
import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';
import { chromium } from '../../browser-worker/node_modules/playwright/index.mjs';
const fixture = JSON.parse(await readFile(process.env.BW_PADDLE_CONTEXT, 'utf8'));
assert.equal(fixture.environment, 'sandbox');
assert.ok(fixture.client_token.startsWith('test_'));
const base = process.env.BW_SECURITY_URL ?? 'https://localhost:8443';
const localTls = ['localhost', '127.0.0.1', '[::1]'].includes(new URL(base).hostname);
const cleanUrl = (value) => { try { const u = new URL(value); return u.origin + u.pathname.replace(/\/(?:che|txn|ctm|sub)_[a-z0-9]+/g, '/[reference]'); } catch { return String(value).slice(0, 120); } };
const cleanError = (value) => String(value).replace(/(?:test|live)_[a-z0-9]+/gi, '[client-token]').replace(/https?:\/\/[^\s"']+/g, cleanUrl).split('\n')[0].slice(0, 250);
const browser = await chromium.launch({ headless: true });
const results = [];
try {
  for (const scenario of (process.env.BW_PADDLE_SCENARIOS ?? 'public-pay,customer-checkout,production-startup').split(',')) {
    const context = await browser.newContext({ ignoreHTTPSErrors: localTls, locale: 'en-GB', viewport: { width: 1280, height: 1000 } });
    const transactionId = fixture.transactions?.[scenario] ?? fixture.transaction_id;
    const result = { scenario, violations: [], errors: [], providerWarnings: [], sdkResources: [], failedResources: [], scripts: [], frameOrigins: [], cardFormReady: false };
    const page = await context.newPage();
    await context.addInitScript(() => {
      window.__securityViolations = [];
      document.addEventListener('securitypolicyviolation', (event) => window.__securityViolations.push({ directive: event.effectiveDirective, disposition: event.disposition, blocked: event.blockedURI, source: event.sourceFile, line: event.lineNumber }));
    });
    page.on('response', (response) => { if (/^https:\/\/(cdn\.paddle\.com|sandbox-cdn\.paddle\.com|public\.profitwell\.com)\//.test(response.url())) result.sdkResources.push({ url: cleanUrl(response.url()), status: response.status() }); if (response.request().isNavigationRequest() && response.frame() === page.mainFrame() && response.url().startsWith(base)) result.trustedTypesEnforced = response.headers()['content-security-policy']?.includes("require-trusted-types-for 'script'") === true; });
    page.on('pageerror', (error) => result.errors.push(cleanError(error.message)));
    page.on('console', (message) => { if (message.type() === 'error') { const text = cleanError(message.text()); if (text.startsWith('[Report Only] Refused to frame') && text.includes('sandbox-buy.paddle.com')) result.providerWarnings.push(text); else result.errors.push(text); } });
    page.on('requestfailed', (request) => result.failedResources.push({ url: cleanUrl(request.url()), error: request.failure()?.errorText }));
    page.on('request', (request) => { if (request.resourceType() === 'script') result.scripts.push(cleanUrl(request.url())); });
    try {
      if (scenario === 'public-pay') {
        await page.goto(`${base}/en/pay?_ptxn=${transactionId}`);
      } else if (scenario === 'customer-checkout') {
        await context.addCookies([{ name: 'XSRF-TOKEN', value: 'security-fixture', url: base }]);
        const session = { user: { id: 'u-security', name: 'Security fixture', salutation: null, first_name: null, last_name: 'Fixture', company_name: null, email: 'security@example.test', locale: 'en', email_verified: true, mfa_enabled: false, pending_email_change: null }, active_tenant_id: 't-security', memberships: [{ tenant_id: 't-security', role: 'owner', permissions: ['billing.manage', 'stores.read'], created_at: '2026-10-29T00:00:00Z' }] };
        const checkout = { id: 'checkout-security', status: 'pending', error_code: null, expires_at: '2099-01-01T00:00:00Z', extra_store_quantity: 0, discounts: null, access_state: 'pilot', provider_checkout: { provider: 'paddle', environment: 'sandbox', client_token: fixture.client_token, transaction_id: transactionId } };
        await page.route('**/api/v1/**', async (route) => {
          const path = new URL(route.request().url()).pathname;
          let json = { data: [] };
          if (path === '/api/v1/auth/me') json = session;
          else if (path === '/api/v1/entitlements') json = { access_state: 'pilot', reason_codes: [], enforced: false, plan_code: null, features: [], limits: { max_active_stores: null, manual_runs_per_day: null, monthly_event_budget: null }, usage: { manual_runs_per_day: { used: 0, period_start: '2026-10-29T00:00:00Z', period_end: '2026-10-30T00:00:00Z' }, monthly_event_budget: { used: 0, period_start: '2026-10-01T00:00:00Z', period_end: '2026-11-01T00:00:00Z' } } };
          else if (path === '/api/v1/billing/subscription') json = null;
          else if (path === '/api/v1/billing/plans') json = { mode: 'test', checkout_available: true, trial_available: false, data: [{ plan_code: 'security', plan_version_id: 'v-security', name: { en: 'Security test' }, placeholder: true, trial_days: 0, entitlements: { features: [], limits: { max_active_stores: 1, max_extra_stores: 0 } }, prices: [{ id: 'p-security', kind: 'base', currency: 'EUR', currency_exponent: 2, amount_minor: '1900', interval: 'month', interval_count: 1, tax_behavior: 'provider_calculated' }], discounts: [] }] };
          else if (path.includes('/billing/checkout')) json = checkout;
          else if (path.includes('/maintenance')) json = null;
          return route.fulfill({ json });
        });
        await page.goto(base + '/app/settings/billing');
        await page.locator('[data-action="buy-security"]').click();
      } else {
        assert.ok(fixture.live_client_token.startsWith('live_'));
        await page.route('**/api/v1/auth/me', (route) => route.fulfill({ json: null }));
        await page.goto(base + '/app/login');
        await page.locator('input[type=password]').waitFor();
        await page.evaluate(async (token) => {
          const script = document.createElement('script'); script.src = 'https://cdn.paddle.com/paddle/v2/paddle.js';
          await new Promise((resolve, reject) => { script.onload = resolve; script.onerror = reject; document.head.append(script); });
          window.Paddle.Initialize({ token });
        }, fixture.live_client_token);
        await page.waitForTimeout(6000);
      }
      if (scenario !== 'production-startup') {
        const frame = page.frameLocator('iframe[name="paddle_frame"], iframe.paddle-frame').first();
        await frame.locator('input[type=email]:visible, input[name=cardNumber]:visible, #cardNumber:visible').first().waitFor({ timeout: 45000 });
        if (await frame.locator('input[type=email]').isVisible()) await frame.locator('input[type=email]').fill('buyer@example.com');
        const country = frame.locator('select[name="countryCode"]');
        if (await country.isVisible()) await country.selectOption('DE');
        const postcode = frame.locator('input[name="postalCode"], input[name="postcode"]').first();
        if (await postcode.isVisible()) await postcode.fill('10115');
        // Only the identity/address step is submitted. No card fields are ever populated or submitted.
        if (!await frame.locator('input[name="cardNumber"], #cardNumber').isVisible()) {
          const next = frame.locator('button[type=submit]').last();
          assert.match(await next.innerText(), /continue|next/i);
          await next.click();
        }
        await frame.locator('input[name="cardNumber"], #cardNumber').waitFor({ timeout: 45000 });
        result.cardFormReady = true;
        await page.waitForTimeout(1200);
        result.paymentOptions = await frame.locator('button').allTextContents();
        if (process.env.BW_PADDLE_POPUPS === '1' && scenario === 'customer-checkout') {
          const opened = context.waitForEvent('page', { timeout: 20000 }).catch(() => null);
          // Opens sandbox PayPal authorization directly; no purchase confirmation or login credentials.
          await frame.getByRole('button', { name: 'PayPal', exact: true }).click();
          const popup = await opened;
          assert.ok(popup, 'PayPal authorization popup should open');
          await popup.waitForURL((url) => url.hostname.endsWith('paypal.com'), { timeout: 30000 });
          result.popup = { url: cleanUrl(popup.url()), sandbox: /(^|\.)sandbox\.paypal\.com$/.test(new URL(popup.url()).hostname), loginFormReady: false };
          assert.equal(result.popup.sandbox, true);
          await popup.locator('input[type=email], input[name=login_email]').first().waitFor({ timeout: 30000 });
          result.popup.loginFormReady = true;
          await popup.close();
        }
        await page.evaluate(() => window.Paddle.Checkout.close());
        await page.waitForTimeout(200);
        result.overlayClosed = (await page.locator('iframe[name="paddle_frame"], iframe.paddle-frame').count()) === 0;
      }
    } catch (error) { result.failure = cleanError(error.message); result.failureState = { url: cleanUrl(page.url()), text: (await page.locator('body').innerText().catch(() => '')).slice(0, 1500) }; process.exitCode = 1; }
    result.sdkInitialized = await page.evaluate(() => window.Paddle?.Initialized === true).catch(() => false);
    result.frameOrigins = [...new Set(page.frames().map((frame) => cleanUrl(frame.url())).filter((url) => !url.startsWith(base)))];
    result.violations = await page.evaluate(() => window.__securityViolations).then((values) => values.map((v) => ({ ...v, blocked: cleanUrl(v.blocked), source: cleanUrl(v.source) }))).catch(() => []);
    result.scripts = [...new Set(result.scripts)];
    result.injectionBlocked = await page.evaluate(() => {
      const result = { html: false, script: false, url: false };
      try { document.createElement('div').innerHTML = '<img src=x onerror=alert(1)>'; } catch { result.html = true; }
      try { document.createElement('script').innerHTML = 'window.__injected=true'; } catch { result.script = true; }
      try { document.createElement('script').src = 'https://example.invalid/evil.js'; } catch { result.url = true; }
      return result;
    }).catch(() => null);
    if (!result.failure && (!result.trustedTypesEnforced || !result.sdkInitialized || result.violations.length || Object.values(result.injectionBlocked ?? {}).filter(Boolean).length !== 3)) {
      result.failure = 'Security invariant failed: enforced TT, zero parent policy violations and all injection probes blocked are required';
      process.exitCode = 1;
    }
    results.push(result);
    await context.close();
  }
} finally { await browser.close(); }
const output = JSON.stringify({ sdk: 'Actual CDN Paddle.js; actual sandbox checkout transaction; no card/payment submitted; production Initialize only', results }, null, 2);
if (process.env.BW_PADDLE_REPORT) await writeFile(process.env.BW_PADDLE_REPORT, output + '\n');
console.log(output);
