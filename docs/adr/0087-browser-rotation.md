# ADR 0087: Browser checks take Chrome, Firefox and Safari in turn

Date: 2026-10-29. Status: accepted (owner, 2026-10-29: «тестировать каждый по очереди — это как раз то, что нужно»; all three in every check — «возможно в будущем, сейчас просто по очереди»).

## Context

Every browser check ran in Chromium only. Checkout breakage that exists only in one browser (stricter third-party cookies in Safari, script differences in Firefox) went unnoticed. Playwright drives Chromium, Firefox (Gecko) and WebKit (the Safari engine on Linux — not Safari on macOS/iOS, so Apple Pay and macOS-only behaviour stay out of reach). The worker image `mcr.microsoft.com/playwright:v1.55.0-noble` already contains all three.

## Decision

1. **Rotation, not triple runs.** Each run of a scenario gets the next browser of `WATCHDOG_BROWSER_ROTATION` (default `chromium,firefox,webkit`) after the scenario's previous run, whatever the trigger; the retries of a run stay in its browser. Load and, for the Shopware guest path, guest customers do not grow. Every browser is checked once per three intervals.
2. **The backend chooses, the worker offers.** `check_runs.browser` (`chromium|firefox|webkit`, default `chromium`). The worker reports its browsers in the lease request (`browser_versions`); a worker without it is Chromium-only. A worker leases only runs of its browsers; a run nobody can take waits 300 s and is then taken in Chromium, and the run records Chromium — no store is blocked by a missing browser. The attempt keeps the exact browser version.
3. **One browser of each kind per worker**, launched at start (Chromium is required; Firefox/WebKit that do not start are logged as `browser_unavailable` and left out). `BW_BROWSERS` limits them. The production sandbox is unchanged (seccomp profile, read-only root, no capabilities, 2 GB); only `HOME=/tmp` in the image, because Firefox needs a writable home on the read-only root.
4. **The cabinet shows the browser** of each run (Chrome / Firefox / Safari (WebKit)).
5. **The public storefront check stays on Chromium.**

## Not yet

- A failure in one browser confirmed or refuted in another, and an incident «checkout fails only in Safari» — next step, it changes the confirmation policy (ADR 0010).
- All three browsers in every check (possibly for higher plans).

## Rollback

`WATCHDOG_BROWSER_ROTATION=chromium` in production `.env` and a rollout: new runs are Chromium only, nothing else changes.
