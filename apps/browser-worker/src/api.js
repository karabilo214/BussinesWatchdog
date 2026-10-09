export class ApiError extends Error {
  constructor(status, code) {
    super(`api_${status}_${code ?? 'unknown'}`);
    this.status = status;
    this.code = code;
  }
}

/**
 * Talks to /internal/v1/browser. The lease token is sent only in its header and is never
 * logged; errors carry the HTTP status and problem code only.
 */
export function createApi({ apiUrl, token, fetchImpl = fetch }) {
  async function call(path, body, headers = {}) {
    const response = await fetchImpl(`${apiUrl}${path}`, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        Authorization: `Bearer ${token}`,
        ...headers,
      },
      body: JSON.stringify(body),
      signal: AbortSignal.timeout(20000),
    });

    if (response.status === 204) {
      return null;
    }

    const json = await response.json().catch(() => null);

    if (!response.ok) {
      throw new ApiError(response.status, json?.code);
    }

    return json;
  }

  return {
    lease(browserVersion, location) {
      return call('/internal/v1/browser/leases', { browser_version: browserVersion, location });
    },
    heartbeat(lease) {
      return call(`/internal/v1/browser/attempts/${lease.attempt_id}/heartbeat`, { fencing_token: lease.fencing_token }, { 'X-BW-Lease-Token': lease.lease_token });
    },
    result(lease, result) {
      return call(`/internal/v1/browser/attempts/${lease.attempt_id}/result`, { fencing_token: lease.fencing_token, ...result }, { 'X-BW-Lease-Token': lease.lease_token });
    },
  };
}
