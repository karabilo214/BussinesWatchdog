# Browser Worker

Node.js + Playwright worker for the P0/P1 `payment_form` check (spec §18–21, ADR 0010/0011).

- Gets work only through the internal lease API (`/internal/v1/browser`) with a per-worker bearer token; it never reads Laravel jobs or the database.
- One fresh browser context per attempt, service workers blocked, heartbeat every 15 s, the attempt stops before the absolute deadline from the lease.
- Walks product → add to cart → cart → checkout → (synthetic location) → payment form and **never** places an order: every mutation must match an allowlist of cart operations; Classic `wc-ajax=checkout`, Store API checkout `POST`, order-pay, gateway capture/confirm and refunds are aborted at the network layer whatever the button says.
- Sends sanitized metadata only: origin + pseudonymised path (no query strings), status codes, error classes. No bodies, headers, cookies, form values or screenshots (artifacts come with Step 53).

## Run

```sh
npm test                     # unit tests (network policy, redaction)
docker build -t bw-browser-worker .
docker run --read-only --tmpfs /tmp --cap-drop ALL --security-opt no-new-privileges \
  -e BW_API_URL=https://backend.internal -e BW_WORKER_TOKEN=... bw-browser-worker
```

Create a token with `php artisan browser:worker-create <name>` (printed once).

| Variable | Default | Meaning |
|---|---|---|
| `BW_API_URL` | — | Backend base URL (private ingress) |
| `BW_WORKER_TOKEN` | — | Worker credential |
| `BW_WORKER_LOCATION` | `local` | Reported location label |
| `BW_CHROMIUM_SANDBOX` | `1` | Chromium sandbox; set `0` only where user namespaces are unavailable |
| `BW_RUN_ONCE` | `0` | Exit after one attempt or when no work is queued (tests) |
| `BW_EGRESS_PROXY` | — | Egress proxy for all browser traffic (required with `NODE_ENV=production`), e.g. `http://browser-egress:3128` |
| `BW_WORKER_INSECURE_LOCAL` | `0` | Allow http and private hosts for the local test matrix; refused when `NODE_ENV=production` |

End-to-end against real WooCommerce versions: `plugins/woocommerce-watchdog/tests/matrix/browser-e2e.sh [target...]` (`make worker-e2e`).

The signed synthetic marker (`X-BW-Synthetic`) is sent to the store origin only; the egress proxy is `infra/docker/egress` (ADR 0012). Not yet in place: screenshots/artifacts.
