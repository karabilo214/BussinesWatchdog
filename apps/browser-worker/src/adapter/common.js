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
  await waitForPreloader(page, Math.min(timeout, 10000));

  if (response && response.status() >= 500) {
    throw SITE(`http_${response.status()}`);
  }

  return response;
}

const PRELOADER = /preload|loader|loading|spinner|splash|pace-|page-load/i;

export async function waitForPreloader(page, timeout) {
  await page.waitForFunction((pattern) => {
    const name = new RegExp(pattern, 'i');
    const width = window.innerWidth;
    const height = window.innerHeight;

    for (const [x, y] of [[width / 2, height / 2], [width / 4, height / 4], [(3 * width) / 4, (3 * height) / 4]]) {
      for (let element = document.elementFromPoint(x, y); element && element !== document.body; element = element.parentElement) {
        const style = getComputedStyle(element);
        const box = element.getBoundingClientRect();
        const label = `${element.id} ${typeof element.className === 'string' ? element.className : ''}`;

        if (['fixed', 'absolute'].includes(style.position) && box.width >= width * 0.8 && box.height >= height * 0.8 && name.test(label)) {
          return false;
        }
      }
    }

    return true;
  }, PRELOADER.source, { timeout, polling: 250 }).catch(() => null);
}
