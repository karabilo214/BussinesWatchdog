import assert from 'node:assert/strict';
import { chromium } from '../../browser-worker/node_modules/playwright/index.mjs';
const reportOnly = process.env.BW_SECURITY_REPORT_ONLY === '1';
const base = process.env.BW_SECURITY_URL ?? 'http://127.0.0.1:8088';
const browser = await chromium.launch({ headless: true });
const results = [];
try {
  const context = await browser.newContext({ ignoreHTTPSErrors: process.env.BW_SECURITY_LOCAL_TLS === '1' });
  await context.addInitScript(() => {
    window.__violations = [];
    document.addEventListener('securitypolicyviolation', (event) => window.__violations.push({ directive: event.effectiveDirective, disposition: event.disposition, blocked: event.blockedURI.split('?')[0] }));
  });
  const page = await context.newPage();
  for (const path of ['/en', '/de', '/ru', '/en/contact', '/en/check-store', '/app/login', '/owner/login']) {
    const response = await page.goto(base + path);
    assert.equal(response.status(), 200, path);
    if (base.startsWith('https:')) assert.match(response.headers()['strict-transport-security'], /max-age=\d+; includeSubDomains/);
    await page.waitForTimeout(600);
    if (path.startsWith('/app') || path.startsWith('/owner')) {
      await page.locator('input[type=password]').waitFor();
      const entry = page.locator('script[src]').first();
      const integrity = await entry.getAttribute('integrity');
      assert.ok(integrity && response.headers()[reportOnly ? 'content-security-policy-report-only' : 'content-security-policy'].includes(integrity), path);
      const asset = await context.request.get(new URL(await entry.getAttribute('src'), base).href);
      assert.match(asset.headers()['cache-control'], /max-age=31536000, immutable/);
    }
    const violations = await page.evaluate(() => window.__violations);
    assert.deepEqual(violations.filter((event) => event.disposition === 'enforce'), [], path);
    results.push({ path, violations });
  }
  for (const path of ['/', '/app', '/site/assets/missing.css', '/does-not-exist', '/app/security.caddy']) {
    const response = await context.request.get(base + path, { maxRedirects: 0 });
    assert.ok(response.headers()['content-security-policy'], path);
    if (base.startsWith('https:')) assert.match(response.headers()['strict-transport-security'], /includeSubDomains/);
    assert.equal(response.headers()['x-frame-options'], 'SAMEORIGIN', path);
    assert.equal(response.headers()['cross-origin-opener-policy'], 'same-origin-allow-popups', path);
    results.push({ path, status: response.status() });
  }
  // Exercise the production editor bundle and its actual DOM sinks, with synthetic API fixtures.
  const ownerContext = await browser.newContext({ ignoreHTTPSErrors: process.env.BW_SECURITY_LOCAL_TLS === '1' });
  await ownerContext.addCookies([{ name: 'BW-PLATFORM-XSRF', value: 'security-fixture', url: base }]);
  await ownerContext.addInitScript(() => {
    window.__violations = [];
    document.addEventListener('securitypolicyviolation', (event) => window.__violations.push({ directive: event.effectiveDirective, disposition: event.disposition }));
  });
  const owner = await ownerContext.newPage();
  const ownerErrors = [];
  owner.on('pageerror', (error) => ownerErrors.push(error.message));
  const staff = { staff_id: 's-1', name: 'Security Fixture', email: 'security@example.test', locale: 'ru', role: 'platform_owner', mfa_enabled: true, capabilities: ['content.manage'], billing_mode: 'test', session: { idle_minutes: 30, absolute_hours: 8 } };
  const empty = { published: null, draft: false, title: null };
  const version = { id: 'v-1', version: 1, title: 'Security editor', description: null, body: '<p>Security editor test</p>', content_hash: 'fixture', updated_at: null, published_at: null };
  const card = { id: 'p-1', kind: 'page', parent_id: null, slug: 'security-fixture', path: 'security-fixture', menu: 'none', footer_group: null, menu_section_id: null, menu_parent_id: null, menu_labels: {}, sort: 0, policy_type: null, editable: true, created_at: null, locales: { ru: empty, en: empty, de: empty }, content: { ru: { draft: version, published: null }, en: { draft: null, published: null }, de: { draft: null, published: null } }, builtin: null, history: [] };
  let signedIn = false;
  await owner.route('**/platform-api/v1/**', async (route) => {
    const path = new URL(route.request().url()).pathname;
    if (path.endsWith('/auth/login')) return route.fulfill({ json: { mfa_required: true } });
    if (path.endsWith('/auth/mfa')) { signedIn = true; return route.fulfill({ json: staff }); }
    if (path.endsWith('/me')) return route.fulfill({ status: signedIn ? 200 : 401, json: signedIn ? staff : { code: 'unauthenticated' } });
    if (path.endsWith('/preview')) {
      const body = route.request().postDataJSON().body;
      return route.fulfill({ json: { body, html: `<!doctype html><html><head><style>p { color: rgb(1, 2, 3) }</style></head><body>${body}</body></html>` } });
    }
    if (path.endsWith('/pages/p-1')) return route.fulfill({ json: card });
    return route.fulfill({ json: { data: [] } });
  });
  await owner.goto(base + '/owner/site/pages/p-1');
  await owner.locator('#staff-email').fill('security@example.test');
  await owner.locator('#staff-password').fill('synthetic-security-password');
  await owner.locator('button[type=submit]').click();
  await owner.locator('[data-step=mfa] input').fill('123456');
  await owner.locator('[data-step=mfa] button[type=submit]').click();
  await owner.locator('[data-visual]').waitFor();
  await owner.frameLocator('[data-preview]').getByText('Security editor test').waitFor();
  const color = await owner.frameLocator('[data-preview]').locator('p').evaluate((p) => getComputedStyle(p).color);
  assert.equal(color, 'rgb(1, 2, 3)');
  await owner.locator('[data-visual]').click();
  await owner.keyboard.type(' edited');
  await owner.locator('[data-mode=html]').click();
  await owner.locator('.cm-editor').waitFor();
  const sanitized = await owner.evaluate(() => {
    const node = document.createElement('div');
    node.innerHTML = '<img src=x onerror="window.__xss=true"><script>window.__xss=true</script>';
    return { html: node.innerHTML, injected: window.__xss === true };
  });
  assert.equal(sanitized.injected, false);
  assert.ok(!sanitized.html.includes('onerror') && !sanitized.html.includes('<script'));
  assert.deepEqual(ownerErrors, []);
  const ownerViolations = await owner.evaluate(() => window.__violations);
  assert.deepEqual(ownerViolations.filter((event) => event.disposition === 'enforce'), []);
  results.push({ editor: 'password/MFA UI fixtures, Tiptap, srcdoc with CSS, CodeMirror, sanitized HTML', violations: ownerViolations });
  await ownerContext.close();
  await page.goto(base + '/en');
  const blocked = await page.evaluate(() => {
    const script = document.createElement('script');
    try { script.textContent = 'window.__injected = true'; document.body.append(script); } catch { /* Trusted Types rejects the sink first. */ }
    let htmlBlocked = false;
    try { document.createElement('div').innerHTML = '<img src=x onerror="window.__injected=true">'; } catch { htmlBlocked = true; }
    return { injected: window.__injected === true, htmlBlocked };
  });
  assert.equal(blocked.injected, reportOnly);
  assert.equal(blocked.htmlBlocked, !reportOnly);
  results.push({ injection: blocked });
  console.log(JSON.stringify(results, null, 2));
} finally {
  await browser.close();
}
