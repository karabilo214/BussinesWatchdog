import { StepOutcome, SITE, UNSUPPORTED, navigate } from './common.js';

export const ADAPTER_VERSION = 'public-storefront@1';

const CHECKOUT_HINT = /checkout|kasse|bestell|cassa|commande|comprar|pagar/i;
const CART_HINT = /cart|basket|warenkorb|panier|carrello|cesta/i;
const ADD_TO_CART_HINT = /add[-_\s]?to[-_\s]?cart|single_add_to_cart|in den warenkorb|warenkorb.*legen|add.*basket|buy button|cart\/add-item|line-item\/add/i;
const LOGIN_HINT = /login|sign in|signin|account|konto|anmelden|register|registrieren/i;
const CAPTCHA_HINT = /captcha|cf-chl|challenge-platform|just a moment|access denied|attention required/i;
const PRIVATE_PATH = /\/(account|my-account|login|register|admin|wp-admin|customer|order|orders)\b/i;
const PII_FIELD = /email|e-mail|mail|phone|tel|address|street|city|postcode|zip|password|passwort|login|username|user/i;
const CONSENT_ACCEPT_HINT = /(accept all|accept cookies|accept|agree|allow all|allow cookies|continue|got it|ok|okay|yes|enter|i am 18|i'm 18|over 18|18\+|confirm age|alle akzeptieren|akzeptieren|zustimmen|einverstanden|weiter|ja|ich bin 18|plus de 18|j'ai 18|accepter|tout accepter|continuer|aceptar|aceptar todo|soy mayor|ho 18 anni|accetta|accetto|proceed)/i;
const CONSENT_REJECT_HINT = /(decline|reject|deny|no|under 18|leave|cancel|ablehnen|nein|minderj|refuser|non|moins de 18|rechazar|menor|rifiuta|annulla)/i;
const DANGEROUS_CONSENT_HINT = /checkout|cart|basket|warenkorb|kasse|payment|pay|buy|purchase|order|login|sign in|register|subscribe|newsletter|account/i;
const PRODUCT_PATH_HINT = /\/(product|produkte|produkt|artikel|p)\/|\/detail\/|\/dp\//i;
const CATEGORY_PATH_HINT = /\/(category|product-category|collections|collection|catalog|shop|c|kategorie|categories|navigation)\b|\/cat\//i;
const CART_PATH_HINT = /cart|basket|warenkorb|panier|carrello|cesta/i;
const CHECKOUT_PATH_HINT = /checkout|kasse|bestell|commande|comprar|pagar/i;
const BAD_DISCOVERY_PATH = /\/(account|login|register|wishlist|compare|search|privacy|policy|terms|contact|blog|news|wp-admin|admin)\b/i;
const PRODUCT_SAMPLE_LIMIT = 5;
const CATEGORY_VISIT_LIMIT = 20;
const PRODUCT_DISCOVERY_LIMIT = 150;

export function createPublicStorefrontAdapter() {
  return {
    focus: {
      public_home: 'body',
      public_checkout: 'main, body',
    },

    async public_home(page, step, state) {
      await navigate(page, state.scenario.target_url ?? state.scenario.store_origin, step.timeout_ms, state);
      await page.locator('body').waitFor({ state: 'visible', timeout: Math.min(5000, step.timeout_ms) }).catch(() => null);
      await acceptPublicEntryOverlays(page);
      await assertNoPrivatePage(page);

      const text = await page.locator('body').innerText({ timeout: 3000 }).catch(() => '');

      if (CAPTCHA_HINT.test(text.slice(0, 3000))) {
        throw new StepOutcome('blocked', 'waf_challenge', 'challenge_page');
      }

      state.detectedPlatform = await detectPlatformHint(page);
      const inventory = await discoverInventory(page, state, Math.min(9000, step.timeout_ms));
      state.publicAudit = {
        platform: state.detectedPlatform,
        supported: ['woocommerce_hint', 'shopware_hint'].includes(state.detectedPlatform),
        categories_found: inventory.categories.length,
        products_found: inventory.products.length,
        products_tested: 0,
        cart_result: 'not_tested',
        checkout_result: 'not_tested',
        sampled_products: [],
        category_samples: inventory.categories.slice(0, 20).map(publicAuditUrl),
        cart_url: inventory.cartUrl ? publicAuditUrl(inventory.cartUrl) : null,
        checkout_url: inventory.checkoutUrl ? publicAuditUrl(inventory.checkoutUrl) : null,
      };

      return [
        { code: 'public_page_loaded', passed: true, detail_code: 'loaded' },
        { code: 'public_inventory_discovered', passed: true, detail_code: `categories_${inventory.categories.length}_products_${inventory.products.length}` },
      ];
    },

    async public_checkout(page, step, state) {
      await acceptPublicEntryOverlays(page);
      await assertNoPrivatePage(page);

      if (!['woocommerce_hint', 'shopware_hint'].includes(state.detectedPlatform)) {
        ensureAudit(state).checkout_result = 'unsupported_platform';
        throw UNSUPPORTED('unsupported_platform');
      }

      const audit = ensureAudit(state);
      const inventory = await discoverInventory(page, state, Math.min(20000, step.timeout_ms));
      audit.categories_found = Math.max(audit.categories_found ?? 0, inventory.categories.length);
      audit.products_found = Math.max(audit.products_found ?? 0, inventory.products.length);
      audit.category_samples = inventory.categories.slice(0, 20).map(publicAuditUrl);
      audit.cart_url = audit.cart_url ?? (inventory.cartUrl ? publicAuditUrl(inventory.cartUrl) : null);
      audit.checkout_url = audit.checkout_url ?? (inventory.checkoutUrl ? publicAuditUrl(inventory.checkoutUrl) : null);

      const productCandidates = inventory.products.slice(0, PRODUCT_SAMPLE_LIMIT);

      for (const productUrl of productCandidates) {
        const sample = { url: publicAuditUrl(productUrl), add_to_cart: 'not_tested', cart_verified: false };
        audit.sampled_products.push(sample);

        try {
          await followPublicLink(page, productUrl, Math.min(12000, step.timeout_ms), state);
          await acceptPublicEntryOverlays(page);
          await assertNoPrivatePage(page);
          audit.products_tested += 1;

          const before = await cartSignal(page).catch(() => null);
          const added = await tryPublicAddToCart(page, Math.min(12000, step.timeout_ms), state);
          sample.add_to_cart = added ? 'clicked' : 'button_not_found';

          if (!added) {
            continue;
          }

          await acceptPublicEntryOverlays(page);
          const after = await cartSignal(page).catch(() => null);
          sample.cart_verified = cartChanged(before, after) || await openCartAndVerify(page, state, audit, Math.min(15000, step.timeout_ms));
          sample.add_to_cart = sample.cart_verified ? 'works' : 'clicked_but_not_verified';

          if (sample.cart_verified) {
            audit.cart_result = 'product_present';
            break;
          }
        } catch (error) {
          sample.add_to_cart = 'error';
          sample.error = error?.detailCode ?? error?.message ?? 'unknown';
        }
      }

      if (audit.cart_result !== 'product_present') {
        audit.cart_result = audit.products_tested > 0 ? 'add_to_cart_not_verified' : 'no_product_tested';
        throw UNSUPPORTED(audit.cart_result);
      }

      const checkoutReached = await proceedToCheckout(page, state, audit, Math.min(18000, step.timeout_ms));

      if (!checkoutReached) {
        audit.checkout_result = 'checkout_not_reached';
        throw UNSUPPORTED('checkout_not_reached');
      }

      audit.checkout_result = checkoutFormKind(page.url());

      return [
        { code: 'public_products_found', passed: audit.products_found > 0, detail_code: `products_${audit.products_found}` },
        { code: 'public_add_to_cart_works', passed: true, detail_code: audit.cart_result },
        { code: 'public_cart_contains_product', passed: true, detail_code: audit.cart_result },
        { code: 'public_checkout_reached', passed: true, detail_code: audit.checkout_result },
      ];
    },
  };
}

async function discoverInventory(page, state, timeout) {
  const deadline = Date.now() + timeout;
  const initial = await collectPublicLinks(page, state);
  const categories = unique(initial.filter((link) => looksLikeCategory(link)).map((link) => link.href)).slice(0, PRODUCT_DISCOVERY_LIMIT);
  const products = unique(initial.filter((link) => looksLikeProduct(link)).map((link) => link.href)).slice(0, PRODUCT_DISCOVERY_LIMIT);
  let cartUrl = initial.find((link) => CART_PATH_HINT.test(`${link.href} ${link.text}`))?.href ?? null;
  let checkoutUrl = initial.find((link) => CHECKOUT_PATH_HINT.test(`${link.href} ${link.text}`))?.href ?? null;

  const listingCandidates = categories.length > 0 ? categories : initial.filter((link) => /shop|store|catalog|products|sortiment|assortiment/i.test(`${link.href} ${link.text}`)).map((link) => link.href);

  for (const href of unique(listingCandidates).slice(0, CATEGORY_VISIT_LIMIT)) {
    if (Date.now() > deadline - 3000 || products.length >= PRODUCT_DISCOVERY_LIMIT) {
      break;
    }

    try {
      await followPublicLink(page, href, Math.min(6000, Math.max(2000, deadline - Date.now())), state);
      await acceptPublicEntryOverlays(page);
      const links = await collectPublicLinks(page, state);

      for (const link of links) {
        if (looksLikeProduct(link) && !products.includes(link.href)) {
          products.push(link.href);
        }

        if (looksLikeCategory(link) && !categories.includes(link.href)) {
          categories.push(link.href);
        }
      }

      cartUrl = cartUrl ?? links.find((link) => CART_PATH_HINT.test(`${link.href} ${link.text}`))?.href ?? null;
      checkoutUrl = checkoutUrl ?? links.find((link) => CHECKOUT_PATH_HINT.test(`${link.href} ${link.text}`))?.href ?? null;
    } catch {
      continue;
    }
  }

  await navigate(page, state.scenario.target_url ?? state.scenario.store_origin, Math.min(5000, Math.max(1500, deadline - Date.now())), state).catch(() => null);
  await acceptPublicEntryOverlays(page);

  return {
    categories: unique(categories).slice(0, PRODUCT_DISCOVERY_LIMIT),
    products: unique(products).slice(0, PRODUCT_DISCOVERY_LIMIT),
    cartUrl,
    checkoutUrl,
  };
}

async function collectPublicLinks(page, state) {
  const origins = allowedStoreOrigins(state);
  const links = await page.locator('a[href]').evaluateAll((nodes, args) => nodes.slice(0, 600).map((node) => {
    try {
      const url = new URL(node.href, window.location.href);

      return {
        href: url.href.split('#')[0],
        origin: url.origin,
        pathname: url.pathname,
        text: (node.textContent ?? '').replace(/\s+/g, ' ').trim().slice(0, 160),
      };
    } catch {
      return null;
    }
  }).filter((link) => link !== null && args.origins.includes(link.origin)), { origins }).catch(() => []);

  return uniqueBy(links.filter((link) => !BAD_DISCOVERY_PATH.test(link.pathname)), (link) => link.href);
}

function looksLikeProduct(link) {
  const haystack = `${link.href} ${link.text}`;

  if (CART_PATH_HINT.test(haystack) || CHECKOUT_PATH_HINT.test(haystack)) {
    return false;
  }

  return PRODUCT_PATH_HINT.test(link.href) || (/product|produkt|artikel|view|details/i.test(haystack) && !looksLikeCategory(link));
}

function looksLikeCategory(link) {
  const haystack = `${link.href} ${link.text}`;

  if (CART_PATH_HINT.test(haystack) || CHECKOUT_PATH_HINT.test(haystack)) {
    return false;
  }

  return CATEGORY_PATH_HINT.test(link.href) || /\b(shop|products|catalog|category|collection|sortiment|kategorie)\b/i.test(haystack);
}

async function cartSignal(page) {
  return await page.locator('body').evaluate((body) => {
    const text = body.innerText.replace(/\s+/g, ' ').slice(0, 6000);
    const counters = Array.from(document.querySelectorAll('[class*="cart"], [id*="cart"], [aria-label*="cart" i], [aria-label*="basket" i]'))
      .map((node) => node.textContent ?? '')
      .join(' ')
      .replace(/\s+/g, ' ');

    return { url: window.location.href, text, counters };
  });
}

function cartChanged(before, after) {
  if (before === null || after === null) {
    return false;
  }

  if (before.url !== after.url && CART_PATH_HINT.test(after.url)) {
    return true;
  }

  return before.counters !== after.counters && /\b(1|one|item|artikel|warenkorb|cart|basket)\b/i.test(after.counters);
}

async function openCartAndVerify(page, state, audit, timeout) {
  const direct = audit.cart_url ?? await linkByHint(page, CART_HINT);

  if (direct === null) {
    return false;
  }

  try {
    await followPublicLink(page, direct, timeout, state);
    await acceptPublicEntryOverlays(page);
    await assertNoPrivatePage(page);
  } catch {
    return false;
  }

  const text = await page.locator('body').innerText({ timeout: 3000 }).catch(() => '');
  const empty = /cart is empty|basket is empty|warenkorb ist leer|empty cart|leerer warenkorb|panier est vide/i.test(text);
  const hasLine = await page.locator('form.cart, .cart_item, .woocommerce-cart-form, [class*="line-item"], [class*="cart-item"], [data-line-item-id], [class*="checkout-cart"]').count().catch(() => 0);

  return !empty && hasLine > 0;
}

async function proceedToCheckout(page, state, audit, timeout) {
  if (audit.cart_url !== null && !CART_PATH_HINT.test(page.url())) {
    await followPublicLink(page, audit.cart_url, Math.min(8000, timeout), state).catch(() => null);
    await acceptPublicEntryOverlays(page);
  }

  const checkout = await linkByHint(page, CHECKOUT_HINT) ?? audit.checkout_url;

  if (checkout === null) {
    return false;
  }

  try {
    await followPublicLink(page, checkout, timeout, state);
    await acceptPublicEntryOverlays(page);
  } catch (error) {
    if (error instanceof StepOutcome && error.errorCode === 'adapter_unsupported') {
      return /checkout|kasse|account|login|register/i.test(page.url());
    }

    return false;
  }

  const fields = await page.locator('input, textarea, select').evaluateAll((nodes) => nodes.map((node) => [
    node.getAttribute('type') ?? '',
    node.getAttribute('name') ?? '',
    node.getAttribute('autocomplete') ?? '',
    node.getAttribute('placeholder') ?? '',
    node.getAttribute('aria-label') ?? '',
  ].join(' ')).join(' ')).catch(() => '');

  return /checkout|kasse|login|register|account/i.test(page.url()) || PII_FIELD.test(fields);
}

function checkoutFormKind(url) {
  if (/login|account|register/i.test(url)) {
    return 'login_or_registration_reached';
  }

  return 'customer_details_reached';
}

function ensureAudit(state) {
  state.publicAudit ??= {
    platform: state.detectedPlatform ?? 'unknown',
    supported: ['woocommerce_hint', 'shopware_hint'].includes(state.detectedPlatform),
    categories_found: 0,
    products_found: 0,
    products_tested: 0,
    cart_result: 'not_tested',
    checkout_result: 'not_tested',
    sampled_products: [],
    category_samples: [],
    cart_url: null,
    checkout_url: null,
  };

  return state.publicAudit;
}

async function acceptPublicEntryOverlays(page) {
  for (let attempt = 0; attempt < 3; attempt++) {
    const index = await page.locator('button, a[href], input[type="button"], input[type="submit"], [role="button"]').evaluateAll((nodes, args) => {
      const accept = new RegExp(args.accept, 'i');
      const reject = new RegExp(args.reject, 'i');
      const dangerous = new RegExp(args.dangerous, 'i');

      function textOf(node) {
        return [
          node.textContent ?? '',
          node.getAttribute('aria-label') ?? '',
          node.getAttribute('title') ?? '',
          node.getAttribute('name') ?? '',
          node.getAttribute('value') ?? '',
          node.getAttribute('id') ?? '',
          node.getAttribute('class') ?? '',
        ].join(' ').replace(/\s+/g, ' ').trim();
      }

      function consentAction(label) {
        const normalized = String(label).replace(/\s+/g, ' ').trim();

        if (normalized === '' || dangerous.test(normalized) || reject.test(normalized)) {
          return 'skip';
        }

        return accept.test(normalized) ? 'accept' : 'skip';
      }

      function overlayLike(node) {
        const scope = node.closest('[role="dialog"], [aria-modal="true"], dialog, .modal, .popup, .overlay, .cookie, .cookies, .consent, .age, .age-gate, .agegate, .verification, .privacy, .gdpr, #onetrust-banner-sdk, #CybotCookiebotDialog');

        if (scope !== null) {
          return true;
        }

        const rect = node.getBoundingClientRect();

        return rect.top < window.innerHeight && rect.left < window.innerWidth;
      }

      return nodes.findIndex((node) => {
        if (!(node instanceof HTMLElement) || node.offsetParent === null || node.hasAttribute('disabled') || !overlayLike(node)) {
          return false;
        }

        return consentAction(textOf(node)) === 'accept';
      });
    }, { accept: CONSENT_ACCEPT_HINT.source, reject: CONSENT_REJECT_HINT.source, dangerous: DANGEROUS_CONSENT_HINT.source }).catch(() => -1);

    if (index < 0) {
      return;
    }

    await page.locator('button, a[href], input[type="button"], input[type="submit"], [role="button"]').nth(index).click({ timeout: 3000 }).catch(() => null);
    await page.waitForTimeout(600);
  }
}

export function publicConsentAction(label) {
  const normalized = String(label).replace(/\s+/g, ' ').trim();

  if (normalized === '' || DANGEROUS_CONSENT_HINT.test(normalized) || CONSENT_REJECT_HINT.test(normalized)) {
    return 'skip';
  }

  return CONSENT_ACCEPT_HINT.test(normalized) ? 'accept' : 'skip';
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

  if (!allowedStoreOrigin(state, target.origin)) {
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

      if (!args.origins.includes(action.origin)) {
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

        return args.origins.includes(target.origin);
      }

      return true;
    });
  }, { origins: allowedStoreOrigins(state), addHint: ADD_TO_CART_HINT.source, pii: PII_FIELD.source }).catch(() => -1);

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

function allowedStoreOrigin(state, origin) {
  return allowedStoreOrigins(state).includes(origin);
}

function allowedStoreOrigins(state) {
  return Array.isArray(state.scenario.allowed_store_origins) && state.scenario.allowed_store_origins.length > 0
    ? state.scenario.allowed_store_origins
    : [state.scenario.store_origin];
}

function unique(values) {
  return [...new Set(values)];
}

function uniqueBy(values, keyOf) {
  const seen = new Set();
  const result = [];

  for (const value of values) {
    const key = keyOf(value);

    if (!seen.has(key)) {
      seen.add(key);
      result.push(value);
    }
  }

  return result;
}

function publicAuditUrl(url) {
  try {
    const parsed = new URL(url);

    return `${parsed.origin}${parsed.pathname}`;
  } catch {
    return String(url).split('?')[0].split('#')[0];
  }
}
