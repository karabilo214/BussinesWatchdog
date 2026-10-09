export function loadConfig(env = process.env) {
  const apiUrl = (env.BW_API_URL ?? '').replace(/\/$/, '');
  const token = env.BW_WORKER_TOKEN ?? '';
  const insecureLocal = env.BW_WORKER_INSECURE_LOCAL === '1';

  if (!/^https?:\/\//.test(apiUrl) || token.length < 32) {
    throw new Error('BW_API_URL and BW_WORKER_TOKEN must be set');
  }

  if (insecureLocal && env.NODE_ENV === 'production') {
    throw new Error('BW_WORKER_INSECURE_LOCAL is not allowed in production');
  }

  const egressProxy = env.BW_EGRESS_PROXY ?? '';

  if (env.NODE_ENV === 'production' && !/^https?:\/\/[^/]+$/.test(egressProxy)) {
    throw new Error('BW_EGRESS_PROXY must be set in production (spec 19: network-level egress control)');
  }

  return {
    apiUrl,
    token,
    insecureLocal,
    location: env.BW_WORKER_LOCATION ?? 'local',
    egressProxy: egressProxy === '' ? null : egressProxy,
    chromiumSandbox: env.BW_CHROMIUM_SANDBOX !== '0',
    runOnce: env.BW_RUN_ONCE === '1',
    idleMinMs: Number(env.BW_IDLE_MIN_MS ?? 2000),
    idleMaxMs: Number(env.BW_IDLE_MAX_MS ?? 30000),
    heartbeatMs: Number(env.BW_HEARTBEAT_MS ?? 15000),
  };
}
