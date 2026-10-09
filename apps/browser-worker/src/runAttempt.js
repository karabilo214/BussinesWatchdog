import { createWooCommerceAdapter, StepOutcome } from './adapter/woocommerce.js';
import { createNetworkPolicy } from './networkPolicy.js';
import { REDACTION_VERSION, errorName, failureCode, redactUrl } from './redact.js';

const MAX_ERRORS = 100;
const MAX_NETWORK_PER_STEP = 50;
const WAF_MARKERS = /cf-chl|challenge-platform|captcha|just a moment|attention required|access denied|ddos protection/i;
const RESULT_STATUSES = ['failed', 'blocked', 'inconclusive'];

export function createErrorLog() {
  const entries = new Map();

  return {
    add(entry) {
      const key = JSON.stringify([entry.type, entry.party, entry.origin, entry.path, entry.status, entry.step_code, entry.message_code]);
      const existing = entries.get(key);

      if (existing) {
        existing.count += 1;
      } else if (entries.size < MAX_ERRORS) {
        entries.set(key, { ...entry, count: 1 });
      }
    },
    list() {
      return [...entries.values()];
    },
  };
}

/**
 * Runs one leased attempt in a fresh browser context and returns the sanitized result.
 * Never places an order: navigation and mutations go through the network policy, and the
 * adapter only reads the payment form.
 */
export async function runAttempt({ browser, lease, insecureLocal = false, now = () => new Date() }) {
  const scenario = lease.scenario;
  const policy = createNetworkPolicy({
    allowedOrigins: lease.network_policy.allowed_origins,
    storeOrigin: scenario.store_origin,
    insecureLocal,
  });
  const deadline = new Date(lease.absolute_deadline_at).getTime() - 5000;
  const errors = createErrorLog();
  const adapter = createWooCommerceAdapter();
  const state = {
    scenario,
    stepCode: null,
    forbiddenOperation: null,
    blockedUnknownMutation: false,
    blockedNavigation: false,
    checkoutMode: null,
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
      const decision = policy.decide({ url: request.url(), method: request.method(), postData: request.postData() });

      if (decision.allow) {
        if (scenario.synthetic_token && policy.party(request.url()) === 'first') {
          await route.continue({ headers: { ...request.headers(), 'x-bw-synthetic': scenario.synthetic_token } });
        } else {
          await route.continue();
        }

        return;
      }

      const { origin, path } = redactUrl(request.url());
      let mainNavigation = false;

      try {
        mainNavigation = request.isNavigationRequest() && request.frame() === request.frame().page().mainFrame();
      } catch {
        mainNavigation = false;
      }

      if (mainNavigation && decision.reason !== 'mutation') {
        state.blockedNavigation = true;
      }

      if (decision.reason === 'mutation' && policy.isPaymentOperation(decision.operation)) {
        state.forbiddenOperation = decision.operation;
      } else if (decision.reason === 'mutation') {
        state.blockedUnknownMutation = true;
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

    page.on('pageerror', (error) => errors.add({ type: 'js_error', party: 'first', step_code: state.stepCode ?? 'setup', message_code: errorName(error) }));
    page.on('request', (request) => started.set(request, Date.now()));
    page.on('requestfailed', (request) => {
      const code = failureCode(request.failure()?.errorText);

      if (code === 'ERR_BLOCKED_BY_CLIENT') {
        return;
      }

      const { origin, path } = redactUrl(request.url());
      errors.add({ type: 'request_failed', party: policy.party(request.url()), origin, path, step_code: state.stepCode ?? 'setup', message_code: code });
    });
    page.on('response', (response) => {
      const request = response.request();
      const party = policy.party(response.url());
      const { origin, path } = redactUrl(response.url());

      if (response.status() >= 500 && party !== 'third') {
        errors.add({ type: 'http_5xx', party, origin, path, status: response.status(), step_code: state.stepCode ?? 'setup' });
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
          throw new StepOutcome('unsupported', 'adapter_unsupported', 'unknown_step');
        }

        const outcome = await handler(page, { ...step, timeout_ms: timeout }, state);

        if (state.forbiddenOperation) {
          throw new StepOutcome('blocked', 'forbidden_mutation', state.forbiddenOperation);
        }

        const skipped = outcome && !Array.isArray(outcome) && outcome.skipped;
        const assertions = Array.isArray(outcome) ? outcome : (outcome?.assertions ?? []);
        steps.push(stepResult(index, step.code, skipped ? 'skipped' : 'passed', startedAt, now(), assertions, state.network, null));
      } catch (error) {
        let outcome = error instanceof StepOutcome ? error : new StepOutcome('inconclusive', 'infra_timeout', 'worker_exception');

        if (outcome.errorCode === 'site_failure' && state.blockedUnknownMutation) {
          outcome = new StepOutcome('unsupported', 'adapter_unsupported', 'blocked_unknown_mutation');
        }

        final = { status: outcome.status, error_code: outcome.errorCode };
        steps.push(stepResult(index, step.code, outcome.status, startedAt, now(), [{ code: `${step.code}_check`, passed: false, detail_code: outcome.detailCode }], state.network, outcome.errorCode));
      }
    }

    return {
      status: final?.status ?? 'passed',
      error_code: final?.error_code ?? null,
      finished_at: now().toISOString(),
      steps,
      diagnostics: { redaction_version: REDACTION_VERSION, relevant_errors: errors.list() },
    };
  } finally {
    await context.close().catch(() => null);
  }
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
