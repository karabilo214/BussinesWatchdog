import { StepOutcome, SITE, UNSUPPORTED, navigate } from './common.js';

export const ADAPTER_VERSION = 'public-storefront@1';

const CHECKOUT_HINT = /checkout|kasse|bestell|cassa|commande|comprar|pagar/i;
const CART_HINT = /cart|basket|warenkorb|panier|carrello|cesta/i;
const ADD_TO_CART_HINT = /add[-_\s]?to[-_\s]?cart|single_add_to_cart|in den warenkorb|warenkorb.*legen|add.*basket|buy button|cart\/add-item|line-item\/add/i;
const LOGIN_HINT = /login|sign in|signin|account|konto|anmelden|register|registrieren/i;
const CAPTCHA_HINT = /captcha|cf-chl|challenge-platform|just a moment|access denied|attention required/i;
const PRIVATE_PATH = /\/(account|my-account|login|register|admin|wp-admin|customer|order|orders)\b/i;
const PII_FIELD = /email|e-mail|mail|phone|tel|address|street|city|postcode|zip|password|passwort|login|username|user/i;

export function createPublicStorefrontAdapter() {
  return {
    focus: {
      public_home: 'body',
      public_checkout: 'main, body',
    },

    async public_home(page, step, state) {
      await navigate(page, state.scenario.target_url ?? state.scenario.store_origin, step.timeout_ms, state);
      await page.locator('body').waitFor({ state: 'visible', timeout: Math.min(5000, step.timeout_ms) }).catch(() => null);
      await assertNoPrivatePage(page);

      const text = await page.locator('body').innerText({ timeout: 3000 }).catch(() => '');

      if (CAPTCHA_HINT.test(text.slice(0, 3000))) {
        throw new StepOutcome('blocked', 'waf_challenge', 'challenge_page');
      }

      state.detectedPlatform = await detectPlatformHint(page);

      return [{ code: 'public_page_loaded', passed: true, detail_code: 'loaded' }];
    },

    async public_checkout(page, step, state) {
      await assertNoPrivatePage(page);

      const direct = await linkByHint(page, CHECKOUT_HINT);

      if (direct !== null) {
        await followPublicLink(page, direct, step.timeout_ms, state);

        return [{ code: 'checkout_link_opened', passed: true, detail_code: 'checkout_reached' }];
      }

      const cart = await linkByHint(page, CART_HINT);

      if (cart !== null) {
        await followPublicLink(page, cart, Math.floor(step.timeout_ms / 2), state);
        await assertNoPrivatePage(page);

        const checkout = await linkByHint(page, CHECKOUT_HINT);

        if (checkout !== null) {
          await followPublicLink(page, checkout, Math.floor(step.timeout_ms / 2), state);

          return [{ code: 'checkout_link_opened', passed: true, detail_code: 'checkout_reached_from_cart' }];
        }

        throw UNSUPPORTED('cart_without_public_checkout_link');
      }

      const added = await tryPublicAddToCart(page, Math.floor(step.timeout_ms / 2), state);

      if (added) {
        await assertNoPrivatePage(page);

        const checkout = await linkByHint(page, CHECKOUT_HINT);

        if (checkout !== null) {
          await followPublicLink(page, checkout, Math.floor(step.timeout_ms / 2), state);

          return [{ code: 'checkout_link_opened', passed: true, detail_code: 'checkout_reached_after_add_to_cart' }];
        }

        const cartAfterAdd = await linkByHint(page, CART_HINT);

        if (cartAfterAdd !== null) {
          await followPublicLink(page, cartAfterAdd, Math.floor(step.timeout_ms / 2), state);
          await assertNoPrivatePage(page);

          const checkoutFromCart = await linkByHint(page, CHECKOUT_HINT);

          if (checkoutFromCart !== null) {
            await followPublicLink(page, checkoutFromCart, Math.floor(step.timeout_ms / 2), state);

            return [{ code: 'checkout_link_opened', passed: true, detail_code: 'checkout_reached_after_cart_add' }];
          }
        }

        throw UNSUPPORTED('add_to_cart_without_public_checkout_link');
      }

      const login = await linkByHint(page, LOGIN_HINT);

      if (login !== null) {
        throw UNSUPPORTED('login_required');
      }

      throw UNSUPPORTED('checkout_link_not_found');
    },
  };
}

async function detectPlatformHint(page) {
  const snippets = await page.evaluate(() => {
    const html = document.documentElement.outerHTML;

    const snippets = [
      html.slice(0, 200000),
      html.slice(-200000),
      Array.from(document.scripts).map((script) => script.src).join(' '),
      Array.from(document.querySelectorAll('link[href]')).map((link) => link.getAttribute('href') ?? '').join(' '),
      document.querySelector('meta[name="generator"]')?.getAttribute('content') ?? '',
      document.body?.className ?? '',
    ].join(' ');

    return snippets;
  }).catch(() => '');

  return detectPlatformFromPublicSignals(snippets);
}

export function detectPlatformFromPublicSignals(snippets) {
  let woo = 0;
  let shopware = 0;

  for (const pattern of [/woocommerce/i, /wc-ajax/i, /wc-block/i, /wp-content\/plugins\/woocommerce/i, /wp-json\/wc\/store/i]) {
    if (pattern.test(snippets)) {
      woo += 1;
    }
  }

  for (const pattern of [
    /shopware/i,
    /\/checkout\/line-item\/add/i,
    /storefront\.js/i,
    /\bis-ctl-/i,
    /\/store-api\//i,
    /sales_channel_context/i,
    /sales_channel_domain/i,
    /shopware_core_framework/i,
    /sw_constants/i,
    /SWSCS[A-Z0-9]+/,
  ]) {
    if (pattern.test(snippets)) {
      shopware += 1;
    }
  }

  if (woo > shopware && woo > 0) {
    return 'woocommerce_hint';
  }

  if (shopware > woo && shopware > 0) {
    return 'shopware_hint';
  }

  return 'unknown';
}

async function linkByHint(page, hint) {
  const links = await page.locator('a[href], button').evaluateAll((nodes) => nodes.slice(0, 200).map((node) => ({
    href: node instanceof HTMLAnchorElement ? node.href : null,
    text: (node.textContent ?? '').trim(),
    aria: node.getAttribute('aria-label') ?? '',
  }))).catch(() => []);

  for (const link of links) {
    const haystack = `${link.href ?? ''} ${link.text} ${link.aria}`;

    if (hint.test(haystack) && link.href !== null) {
      return link.href;
    }
  }

  return null;
}

async function followPublicLink(page, href, timeout, state) {
  const target = new URL(href, state.scenario.store_origin);

  if (target.origin !== state.scenario.store_origin) {
    throw new StepOutcome('blocked', 'blocked_external_origin', 'left_store_origin');
  }

  if (PRIVATE_PATH.test(target.pathname)) {
    throw UNSUPPORTED('login_required');
  }

  const response = await navigate(page, target.href, timeout, state);
  await assertNoPrivatePage(page);

  if (response && response.status() >= 400 && response.status() < 500) {
    throw SITE(`http_${response.status()}`);
  }
}

async function tryPublicAddToCart(page, timeout, state) {
  const index = await page.locator('a[href], button, input[type="submit"], input[type="button"]').evaluateAll((nodes, args) => {
    const origin = args.origin;
    const addHint = new RegExp(args.addHint, 'i');
    const pii = new RegExp(args.pii, 'i');

    function textOf(node) {
      return [
        node.textContent ?? '',
        node.getAttribute('aria-label') ?? '',
        node.getAttribute('title') ?? '',
        node.getAttribute('name') ?? '',
        node.getAttribute('value') ?? '',
        node.getAttribute('class') ?? '',
        node instanceof HTMLAnchorElement ? node.href : '',
      ].join(' ');
    }

    function safeForm(node) {
      const form = node.closest('form');

      if (form === null) {
        return true;
      }

      let action;

      try {
        action = new URL(form.getAttribute('action') || window.location.href, window.location.href);
      } catch {
        return false;
      }

      if (action.origin !== origin) {
        return false;
      }

      for (const field of Array.from(form.querySelectorAll('input, textarea, select'))) {
        const haystack = [
          field.getAttribute('type') ?? '',
          field.getAttribute('name') ?? '',
          field.getAttribute('autocomplete') ?? '',
          field.getAttribute('placeholder') ?? '',
          field.getAttribute('aria-label') ?? '',
        ].join(' ');

        if (pii.test(haystack)) {
          return false;
        }
      }

      return true;
    }

    return nodes.findIndex((node) => {
      if (!(node instanceof HTMLElement) || node.offsetParent === null || node.hasAttribute('disabled')) {
        return false;
      }

      if (!addHint.test(textOf(node)) || !safeForm(node)) {
        return false;
      }

      if (node instanceof HTMLAnchorElement) {
        const target = new URL(node.href, window.location.href);

        return target.origin === origin;
      }

      return true;
    });
  }, { origin: state.scenario.store_origin, addHint: ADD_TO_CART_HINT.source, pii: PII_FIELD.source }).catch(() => -1);

  if (index < 0) {
    return false;
  }

  const candidate = page.locator('a[href], button, input[type="submit"], input[type="button"]').nth(index);
  const before = page.url();
  await candidate.click({ timeout: Math.min(timeout, 8000) });
  await Promise.race([
    page.waitForURL((url) => url.href !== before, { timeout: Math.min(timeout, 8000) }),
    page.waitForLoadState('networkidle', { timeout: Math.min(timeout, 8000) }),
    page.waitForTimeout(1500),
  ]).catch(() => null);
  await page.waitForLoadState('domcontentloaded', { timeout: 3000 }).catch(() => null);

  return true;
}

async function assertNoPrivatePage(page) {
  const url = new URL(page.url());

  if (PRIVATE_PATH.test(url.pathname)) {
    throw UNSUPPORTED('login_required');
  }

  const password = await page.locator('input[type="password"]').count().catch(() => 0);

  if (password > 0) {
    throw UNSUPPORTED('login_required');
  }
}
