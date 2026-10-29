import { ApiError, createApi } from './api.js';
import { launchBrowsers } from './browsers.js';
import { loadConfig } from './config.js';
import { runAttempt } from './runAttempt.js';

const config = loadConfig();
const api = createApi(config);
let stopping = false;

process.on('SIGTERM', () => { stopping = true; });
process.on('SIGINT', () => { stopping = true; });

function log(event, fields = {}) {
  process.stdout.write(`${JSON.stringify({ at: new Date().toISOString(), event, ...fields })}\n`);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function submit(lease, result) {
  const until = new Date(lease.lease_until).getTime() + 30000;

  for (let attempt = 0; Date.now() < until; attempt++) {
    try {
      await api.result(lease, result);

      return 'accepted';
    } catch (error) {
      if (error instanceof ApiError && error.status >= 400 && error.status < 500) {
        return `rejected_${error.status}_${error.code ?? 'unknown'}`;
      }

      await sleep(Math.min(10000, 1000 * 2 ** attempt));
    }
  }

  return 'gave_up';
}

async function main() {
  const browsers = await launchBrowsers(config, log);
  let idle = config.idleMinMs;
  log('worker_started', { browsers: browsers.versions, location: config.location, sandbox: config.chromiumSandbox, egress_proxy: config.egressProxy !== null });

  try {
    while (!stopping) {
      let lease;

      try {
        lease = await api.lease(browsers.versions.chromium, config.location, browsers.versions);
      } catch (error) {
        log('lease_error', { status: error.status ?? null, code: error.code ?? null });
        await sleep(idle);
        idle = Math.min(config.idleMaxMs, idle * 2);

        continue;
      }

      if (lease === null) {
        if (config.runOnce) {
          break;
        }

        await sleep(idle + Math.floor(Math.random() * 1000));
        idle = Math.min(config.idleMaxMs, idle * 2);

        continue;
      }

      idle = config.idleMinMs;
      const picked = browsers.pick(lease);
      log('attempt_started', { attempt_id: lease.attempt_id, run_id: lease.run_id, fencing_token: lease.fencing_token, browser: picked.name });

      const heartbeat = setInterval(() => {
        api.heartbeat(lease)
          .then((renewed) => { if (renewed?.lease_until) lease.lease_until = renewed.lease_until; })
          .catch((error) => log('heartbeat_error', { attempt_id: lease.attempt_id, status: error.status ?? null, code: error.code ?? null }));
      }, config.heartbeatMs);

      let result;

      try {
        result = await runAttempt({
          browser: picked.browser,
          lease,
          insecureLocal: config.insecureLocal,
          uploadScreenshot: (bytes, sha256) => api.artifact(lease, bytes, sha256),
        });
      } catch (error) {
        result = {
          status: 'inconclusive',
          error_code: 'infra_timeout',
          finished_at: new Date().toISOString(),
          steps: [],
          diagnostics: { redaction_version: 'r1', relevant_errors: [{ type: 'worker_exception', message_code: error?.name ?? 'Error' }] },
        };
      } finally {
        clearInterval(heartbeat);
      }

      const delivery = await submit(lease, result);
      log('attempt_finished', { attempt_id: lease.attempt_id, status: result.status, error_code: result.error_code, delivery });

      if (config.runOnce) {
        break;
      }
    }
  } finally {
    await browsers.close();
  }
}

main().catch((error) => {
  log('worker_crashed', { error: error?.name ?? 'Error', message: String(error?.message ?? '').slice(0, 200) });
  process.exitCode = 1;
});
