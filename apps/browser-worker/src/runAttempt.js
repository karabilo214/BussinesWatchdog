import { createHash } from 'node:crypto';
import { StepOutcome } from './adapter/common.js';
import { ADAPTER_VERSION as PUBLIC_STOREFRONT_ADAPTER, createPublicStorefrontAdapter } from './adapter/publicStorefront.js';
import { ADAPTER_VERSION as SHOPWARE_ADAPTER, createShopwareAdapter } from './adapter/shopware.js';
import { ADAPTER_VERSION as WOOCOMMERCE_ADAPTER, createWooCommerceAdapter } from './adapter/woocommerce.js';
import { createNetworkPolicy } from './networkPolicy.js';
import { errorSourceUrl, publicConsoleCause, publicConsoleFinding, publicConsoleSourceUrl, reportableRequestFailure } from './publicDiagnostics.js';
import { REDACTION_VERSION, errorName, failureCode, redactUrl } from './redact.js';

const MAX_ERRORS = 100;
const MAX_NETWORK_PER_STEP = 50;
const WAF_MARKERS = /cf-chl|challenge-platform|captcha|just a moment|attention required|access denied|ddos protection/i;
const RESULT_STATUSES = ['failed', 'blocked', 'inconclusive'];

const ADAPTERS = {
  [WOOCOMMERCE_ADAPTER]: createWooCommerceAdapter,
  [SHOPWARE_ADAPTER]: createShopwareAdapter,
  [PUBLIC_STOREFRONT_ADAPTER]: createPublicStorefrontAdapter,
};

/**
 * Everything a customer could type or a gateway could render is painted over before the
 * screenshot leaves the browser: form fields, editable areas, iframes (card fields) and
 * address blocks.
 */
export const SCREENSHOT_MASK = [
  'input',
  'textarea',
  'select',
  'iframe',
  '[contenteditable="true"]',
  'address',
  '.woocommerce-customer-details',
  '.woocommerce-order-details',
  '.wc-block-components-address-card',
  '.wc-block-components-address-form',
  '.wc-block-components-text-input',
  '.select2-container',
  '.wc-block-components-combobox',
  '.confirm-address',
  '.address',
  '.account-address',
  'form.login-form',
  'form.register-form',
];

const FOCUS = {
  product: 'form.cart, .product',
  add_to_cart: '.woocommerce-error, .woocommerce-notices-wrapper, form.cart',
  cart: '.woocommerce-cart-form, .wp-block-woocommerce-cart, .cart-empty',
  checkout: 'form.checkout, .wp-block-woocommerce-checkout, .woocommerce-error',
  payment_form: '#payment, .wc-block-checkout__payment-method, .wc-block-checkout__no-payment-methods-notice',
  public_home: 'body',
  public_checkout: 'main, body',
};

export function createErrorLog() {
  const entries = new Map();
  let publicCount = 0;
  let publicTruncated = false;
  const publicTypes = new Set(['console_error', 'console_warning', 'js_error', 'request_failed', 'http_4xx_resource', 'http_5xx']);

  return {
    add(entry) {
      const reportable = entry.party !== 'third' && publicTypes.has(entry.type);
      if (reportable) publicCount += 1;
      const key = JSON.stringify([entry.type, entry.party, entry.origin, entry.path, entry.status, entry.step_code, entry.message_code]);
      const existing = entries.get(key);

      if (existing) {
        existing.count += 1;
      } else if (entries.size < MAX_ERRORS) {
        entries.set(key, { ...entry, count: 1 });
      } else if (reportable) {
        publicTruncated = true;
      }
    },
    list() {
      return [...entries.values()];
    },
    publicCount() { return publicCount; },
    publicTruncated() { return publicTruncated; },
  };
}

/**
 * Runs one leased attempt in a fresh browser context and returns the sanitized result.
 * Never places an order: navigation and mutations go through the network policy, and the
 * adapter only reads the payment form.
 */
export async function runAttempt({ browser, lease, insecureLocal = false, now = () => new Date(), uploadScreenshot = null }) {
  const scenario = lease.scenario;
  const policy = createNetworkPolicy({
    allowedOrigins: lease.network_policy.allowed_origins,
    storeOrigin: scenario.store_origin,
    insecureLocal,
    platform: scenario.platform ?? 'woocommerce',
    checkoutAccess: scenario.checkout_access ?? null,
    quietPaths: lease.network_policy.quiet_paths ?? [],
  });
  const deadline = new Date(lease.absolute_deadline_at).getTime() - 5000;
  const errors = createErrorLog();
  const adapter = ADAPTERS[scenario.adapter_version]?.() ?? {};
  const focusOf = { ...FOCUS, ...(adapter.focus ?? {}) };
  const state = {
    scenario,
    stepCode: null,
    forbiddenOperation: null,
    blockedUnknownMutation: false,
    blockedNavigation: false,
    blockedScripts: false,
    checkoutMode: null,
    lineItemId: null,
    signedIn: false,
    network: [],
    async detectWaf(page, response) {
      if (!response || ![403, 429, 503].includes(response.status())) {
        return;
      }

      const headers = response.headers();
      const title = await page.title().catch(() => '');
      const body = await page.locator('body').innerText({ timeout: 2000 }).catch(() => '');

      if (headers['cf-mitigated'] === 'challenge' || WAF_MARKERS.test(title) || WAF_MARKERS.test(body.slice(0, 2000))) {
        throw new StepOutcome('blocked', 'waf_challenge', `http_${response.status()}`);
      }
    },
  };

  const context = await browser.newContext({
    serviceWorkers: 'block',
    acceptDownloads: false,
    ignoreHTTPSErrors: insecureLocal,
    locale: 'en-US',
    viewport: { width: 1280, height: 900 },
  });

  try {
    await context.route('**/*', async (route) => {
      const request = route.request();
      let mainNavigation = false;

      try {
        mainNavigation = request.isNavigationRequest() && request.frame() === request.frame().page().mainFrame();
      } catch {
        mainNavigation = false;
      }

      const decision = policy.decide({ url: request.url(), method: request.method(), postData: request.postData(), isNavigation: mainNavigation });

      if (decision.reason === 'quiet') {
        await route.abort('blockedbyclient');

        return;
      }

      if (decision.allow) {
        if (scenario.synthetic_token && policy.party(request.url()) === 'first') {
          await route.continue({ headers: { ...request.headers(), 'x-bw-synthetic': scenario.synthetic_token } });
        } else {
          await route.continue();
        }

        return;
      }

      const { origin, path } = redactUrl(request.url());
      if (mainNavigation && decision.reason !== 'mutation') {
        state.blockedNavigation = true;
      }

      if (decision.reason === 'mutation' && policy.isPaymentOperation(decision.operation)) {
        state.forbiddenOperation = decision.operation;
      } else if (decision.reason === 'mutation') {
        state.blockedUnknownMutation = true;
      } else if (request.resourceType() === 'script' && decision.reason !== 'quiet' && /(?:\/woocommerce\/|\/wp-includes\/|jquery|checkout|cart|variation|\/bundles\/storefront\/)/i.test(new URL(request.url()).pathname)) {
        state.blockedScripts = true;
      }

      errors.add({
        type: decision.reason === 'mutation' ? 'blocked_mutation' : 'blocked_origin',
        party: policy.party(request.url()),
        origin,
        path,
        step_code: state.stepCode ?? 'setup',
        message_code: decision.operation ?? decision.reason,
      });
      await route.abort('blockedbyclient');
    });

    if (typeof context.routeWebSocket === 'function') {
      await context.routeWebSocket(/.*/, (socket) => {
        if (policy.decide({ url: socket.url().replace(/^ws/, 'http'), method: 'GET' }).allow) {
          socket.connectToServer();
        } else {
          socket.close();
        }
      });
    }

    const page = await context.newPage();
    const started = new Map();

    page.on('pageerror', (error) => {
      const source = errorSourceUrl(error);
      const location = source ? redactUrl(source) : {};
      errors.add({ type: 'js_error', party: source ? policy.party(source) : 'first', ...location, step_code: state.stepCode ?? 'setup', message_code: errorName(error), cause_code: 'script_exception' });
    });
    page.on('console', (message) => {
      if (!['error', 'warning'].includes(message.type())) {
        return;
      }

      if (scenario.platform === 'public') {
        const source = publicConsoleSourceUrl(message) ?? message.location()?.url;
        const party = source ? policy.party(source) : 'first';
        const finding = publicConsoleFinding(message, party);

        if (finding !== null) {
          errors.add({ type: `console_${message.type()}`, party, ...(source ? redactUrl(source) : {}), step_code: state.stepCode ?? 'setup', message_code: finding, cause_code: publicConsoleCause(message) });
        }

        return;
      }

      errors.add({ type: `console_${message.type()}`, party: 'first', step_code: state.stepCode ?? 'setup', message_code: String(message.text()).slice(0, 120) });
    });
    page.on('request', (request) => started.set(request, Date.now()));
    page.on('requestfailed', (request) => {
      const code = failureCode(request.failure()?.errorText);

      const party = policy.party(request.url());

      if (scenario.platform === 'public' ? !reportableRequestFailure(code, party, request.resourceType()) : code === 'ERR_BLOCKED_BY_CLIENT') {
        return;
      }

      const { origin, path } = redactUrl(request.url());
      errors.add({ type: 'request_failed', party, origin, path, step_code: state.stepCode ?? 'setup', message_code: code, cause_code: 'network_failure' });
    });
    page.on('response', (response) => {
      const request = response.request();
      const party = policy.party(response.url());
      const { origin, path } = redactUrl(response.url());

      if (response.status() >= 500 && party !== 'third') {
        errors.add({ type: 'http_5xx', party, origin, path, status: response.status(), step_code: state.stepCode ?? 'setup', cause_code: 'server_response' });
      } else if (scenario.platform === 'public' && response.status() >= 400 && response.status() < 500 && party === 'first' && ['script', 'stylesheet'].includes(request.resourceType())) {
        errors.add({ type: 'http_4xx_resource', party, origin, path, status: response.status(), step_code: state.stepCode ?? 'setup', cause_code: 'resource_http_error' });
      }

      if (party === 'first' && ['document', 'xhr', 'fetch'].includes(request.resourceType()) && state.network.length < MAX_NETWORK_PER_STEP) {
        state.network.push({
          method: request.method(),
          origin,
          path,
          status: response.status(),
          duration_ms: Math.max(0, Date.now() - (started.get(request) ?? Date.now())),
          party,
        });
      }
    });

    const steps = [];
    let final = null;
    const artifactIds = [];

    for (const [index, step] of scenario.steps.entries()) {
      const startedAt = now();

      if (final !== null) {
        steps.push(stepResult(index, step.code, 'skipped', startedAt, startedAt, [], [], null));

        continue;
      }

      const remaining = deadline - Date.now();

      if (remaining < 2000) {
        final = { status: 'inconclusive', error_code: 'infra_timeout' };
        steps.push(stepResult(index, step.code, 'inconclusive', startedAt, now(), [], [], 'infra_timeout'));

        continue;
      }

      state.stepCode = step.code;
      state.network = [];
      state.blockedUnknownMutation = false;
      const handler = adapter[step.code];
      const timeout = Math.min(step.timeout_ms ?? 20000, remaining);

      try {
        if (typeof handler !== 'function') {
          throw new StepOutcome('unsupported', 'adapter_unsupported', ADAPTERS[scenario.adapter_version] ? 'unknown_step' : 'unknown_adapter');
        }

        const outcome = await handler(page, { ...step, timeout_ms: timeout }, state);

        if (state.forbiddenOperation) {
          throw new StepOutcome('blocked', 'forbidden_mutation', state.forbiddenOperation);
        }

        const skipped = outcome && !Array.isArray(outcome) && outcome.skipped;
        const assertions = Array.isArray(outcome) ? outcome : (outcome?.assertions ?? []);
        const failedAssertion = assertions.find((assertion) => assertion.passed === false);
        if (failedAssertion) {
          const assertionFailure = new StepOutcome('inconclusive', 'selector_changed', failedAssertion.detail_code ?? failedAssertion.code);
          assertionFailure.assertions = assertions;
          throw assertionFailure;
        }
        steps.push(stepResult(index, step.code, skipped ? 'skipped' : 'passed', startedAt, now(), assertions, state.network, null));

        if (scenario.capture_steps === true && !skipped && uploadScreenshot !== null && artifactIds.length < (scenario.max_artifacts ?? 3) && deadline - Date.now() > 8000) {
          const artifactId = await captureScreenshot(page, focusOf[step.code], uploadScreenshot, errors).catch(() => null);

          if (artifactId !== null) {
            artifactIds.push(artifactId);
          }
        }
      } catch (error) {
        let outcome = error instanceof StepOutcome ? error : new StepOutcome('inconclusive', 'infra_timeout', 'worker_exception');

        if (outcome.errorCode === 'site_failure' && state.blockedUnknownMutation) {
          outcome = new StepOutcome('unsupported', 'adapter_unsupported', 'blocked_unknown_mutation');
        } else if (outcome.errorCode === 'site_failure' && state.blockedScripts) {
          outcome = new StepOutcome('blocked', 'blocked_external_origin', 'script_origin_not_allowed');
        }

        final = { status: outcome.status, error_code: outcome.errorCode };
        steps.push(stepResult(index, step.code, outcome.status, startedAt, now(), error instanceof StepOutcome && Array.isArray(error.assertions) ? error.assertions : [{ code: `${step.code}_check`, passed: false, detail_code: outcome.detailCode }], state.network, outcome.errorCode));
      }
    }

    if (final !== null && uploadScreenshot !== null && deadline - Date.now() > 8000) {
      const focus = focusOf[steps.find((step) => step.status !== 'passed' && step.status !== 'skipped')?.code];
      const artifactId = await captureScreenshot(page, focus, uploadScreenshot, errors).catch(() => null);

      if (artifactId !== null && !artifactIds.includes(artifactId)) {
        artifactIds.push(artifactId);
      }
    }

    if (typeof adapter.cleanup === 'function' && deadline - Date.now() > 5000) {
      state.stepCode = 'cleanup';

      try {
        const outcome = await adapter.cleanup(page, state, Math.min(15000, deadline - Date.now() - 2000));

        if (outcome !== null && outcome !== 'cart_cleaned' && outcome !== 'cart_clean') {
          errors.add({ type: 'cleanup_failed', party: 'first', step_code: 'cleanup', message_code: outcome });
        }
      } catch (error) {
        errors.add({ type: 'cleanup_failed', party: 'first', step_code: 'cleanup', message_code: error?.detailCode ?? errorName(error) });
      }
    }

    return {
      ...(artifactIds.length > 0 ? { artifact_ids: artifactIds } : {}),
      status: final?.status ?? 'passed',
      error_code: final?.error_code ?? null,
      finished_at: now().toISOString(),
      steps,
      diagnostics: {
        redaction_version: REDACTION_VERSION,
        relevant_errors: errors.list(),
        ...(scenario.platform === 'public' ? { public_error_count: errors.publicCount(), public_errors_truncated: errors.publicTruncated() } : {}),
        ...(state.detectedPlatform ? { detected_platform: state.detectedPlatform } : {}),
        ...(state.publicAudit ? { public_audit: state.publicAudit } : {}),
      },
    };
  } finally {
    await context.close().catch(() => null);
  }
}

async function captureScreenshot(page, focus, uploadScreenshot, errors) {
  try {
    if (focus) {
      await page.locator(focus).first().scrollIntoViewIfNeeded({ timeout: 2000 }).catch(() => null);
    }

    await waitForVisualReady(page);

    const bytes = await page.screenshot({
      type: 'jpeg',
      quality: 60,
      fullPage: false,
      animations: 'disabled',
      caret: 'hide',
      mask: SCREENSHOT_MASK.map((selector) => page.locator(selector)),
      maskColor: '#7f7f7f',
      timeout: 5000,
    });

    return await uploadScreenshot(bytes, createHash('sha256').update(bytes).digest('hex'));
  } catch (error) {
    errors.add({ type: 'artifact_failed', party: 'first', step_code: 'artifact', message_code: error?.code ?? errorName(error) });

    return null;
  }
}

async function waitForVisualReady(page) {
  await page.waitForLoadState('domcontentloaded', { timeout: 2000 }).catch(() => null);
  await page.waitForLoadState('networkidle', { timeout: 4000 }).catch(() => null);
  await page.evaluate(async () => {
    const images = Array.from(document.images)
      .filter((image) => {
        const rect = image.getBoundingClientRect();

        return rect.bottom >= 0 && rect.top <= window.innerHeight && rect.right >= 0 && rect.left <= window.innerWidth;
      })
      .slice(0, 30);

    for (const image of images) {
      image.loading = 'eager';
    }

    await Promise.all(images.map((image) => {
      if (image.complete && image.naturalWidth > 0) {
        return Promise.resolve();
      }

      if (typeof image.decode === 'function') {
        return Promise.race([image.decode().catch(() => null), new Promise((resolve) => setTimeout(resolve, 1800))]);
      }

      return new Promise((resolve) => {
        image.addEventListener('load', resolve, { once: true });
        image.addEventListener('error', resolve, { once: true });
        setTimeout(resolve, 1500);
      });
    }));
  }).catch(() => null);
}

function stepResult(index, code, status, startedAt, finishedAt, assertions, network, errorCode) {
  return {
    index,
    code,
    status: status === 'unsupported' ? 'blocked' : (RESULT_STATUSES.includes(status) || ['passed', 'skipped'].includes(status) ? status : 'inconclusive'),
    started_at: startedAt.toISOString(),
    finished_at: finishedAt.toISOString(),
    assertions: assertions.map((assertion) => sanitizeAssertion(assertion)),
    network_summary: network.slice(0, MAX_NETWORK_PER_STEP),
    error_code: errorCode,
  };
}

function sanitizeAssertion(assertion) {
  const clean = { code: String(assertion.code), passed: Boolean(assertion.passed) };

  if (assertion.detail_code) {
    clean.detail_code = String(assertion.detail_code).slice(0, 64);
  }

  return clean;
}
