export class StepOutcome extends Error {
  constructor(status, errorCode, detailCode) {
    super(detailCode);
    this.status = status;
    this.errorCode = errorCode;
    this.detailCode = detailCode;
  }
}

export const SITE = (detail) => new StepOutcome('failed', 'site_failure', detail);
export const PRODUCT = (detail) => new StepOutcome('failed', 'product_unavailable', detail);
export const UNSUPPORTED = (detail) => new StepOutcome('unsupported', 'adapter_unsupported', detail);

export async function visible(page, selector, timeout) {
  try {
    await page.locator(selector).first().waitFor({ state: 'visible', timeout });

    return true;
  } catch {
    return false;
  }
}

export async function present(page, selector) {
  return (await page.locator(selector).count()) > 0;
}

export async function navigate(page, url, timeout, context) {
  let response;

  try {
    response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout });
  } catch (error) {
    if (context.blockedNavigation) {
      throw new StepOutcome('blocked', 'blocked_external_origin', 'navigation_left_allowed_origins');
    }

    throw new StepOutcome('inconclusive', 'infra_timeout', 'navigation_timeout');
  }

  await context.detectWaf(page, response);

  if (response && response.status() >= 500) {
    throw SITE(`http_${response.status()}`);
  }

  return response;
}
