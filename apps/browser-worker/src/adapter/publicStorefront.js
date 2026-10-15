import { StepOutcome, SITE, UNSUPPORTED, navigate } from './common.js';

export const ADAPTER_VERSION = 'public-storefront@1';

const CHECKOUT_HINT = /checkout|kasse|bestell|cassa|commande|comprar|pagar/i;
const CART_HINT = /cart|basket|warenkorb|panier|carrello|cesta/i;
const ADD_TO_CART_HINT = /add[-_\s]?to[-_\s]?cart|single_add_to_cart|in den warenkorb|warenkorb.*legen|add.*basket|buy button|cart\/add-item|line-item\/add/i;
const LOGIN_HINT = /login|sign in|signin|account|konto|anmelden|register|registrieren/i;
const CAPTCHA_HINT = /captcha|cf-chl|challenge-platform|just a moment|access denied|attention required/i;
const PRIVATE_PATH = /\/(account|my-account|login|register|admin|wp-admin|customer|order|orders)\b/i;
const PII_FIELD = /email|e-mail|mail|phone|tel|address|street|city|postcode|zip|password|passwort|login|username|user/i;
const CONSENT_ACCEPT_HINT = /(\baccept(?: all| cookies)?\b|\bagree\b|\ballow all\b|\ballow cookies\b|\bcontinue\b|\bgot it\b|\bok(?:ay)?\b|\byes\b|\benter\b|i am 18|i'm 18|over 18|18\+|confirm age|alle akzeptieren|alles akzeptieren|akzeptieren|zustimmen|einverstanden|weiter|\bja\b|ich bin 18|plus de 18|j'ai 18|accepter|tout accepter|continuer|aceptar|aceptar todo|soy mayor|ho 18 anni|accetta|accetto|\bproceed\b)/i;
const CONSENT_REJECT_HINT = /(decline|reject|deny|no|under 18|leave|cancel|ablehnen|nein|minderj|refuser|non|moins de 18|rechazar|menor|rifiuta|annulla)/i;
const DANGEROUS_CONSENT_HINT = /checkout|cart|basket|warenkorb|kasse|payment|pay|buy|purchase|order|login|sign in|register|subscribe|newsletter|account/i;
const PRODUCT_PATH_HINT = /\/(product|artikel|p)\/|\/detail\/|\/dp\//i;
const CATEGORY_PATH_HINT = /\/(category|product-category|collections|collection|catalog|shop|c|kategorie|categories|navigation|produkte)\b|\/cat\//i;
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
      state.publicInventory = inventory;
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
      const stepDeadline = Date.now() + step.timeout_ms;
      const remaining = () => Math.max(0, stepDeadline - Date.now());
      await acceptPublicEntryOverlays(page);
      await assertNoPrivatePage(page);

      if (!['woocommerce_hint', 'shopware_hint'].includes(state.detectedPlatform)) {
        ensureAudit(state).checkout_result = 'unsupported_platform';
        throw UNSUPPORTED('unsupported_platform');
      }

      const audit = ensureAudit(state);
      const inventory = state.publicInventory?.products.length > 0
        ? state.publicInventory
        : await discoverInventory(page, state, Math.min(20000, remaining()));
      audit.categories_found = Math.max(audit.categories_found ?? 0, inventory.categories.length);
      audit.products_found = Math.max(audit.products_found ?? 0, inventory.products.length);
      audit.category_samples = inventory.categories.slice(0, 20).map(publicAuditUrl);
      audit.cart_url = audit.cart_url ?? (inventory.cartUrl ? publicAuditUrl(inventory.cartUrl) : null);
      audit.checkout_url = audit.checkout_url ?? (inventory.checkoutUrl ? publicAuditUrl(inventory.checkoutUrl) : null);

      const productCandidates = inventory.products.slice(0, PRODUCT_SAMPLE_LIMIT);

      for (const productUrl of productCandidates) {
        if (remaining() < 8000) {
          break;
        }

        const sample = { url: publicAuditUrl(productUrl), add_to_cart: 'not_tested', cart_verified: false };
        audit.sampled_products.push(sample);

        try {
          await followPublicLink(page, productUrl, Math.min(10000, remaining()), state);
          await acceptPublicEntryOverlays(page);
          await assertNoPrivatePage(page);
          audit.products_tested += 1;

          const addResult = await tryPublicAddToCart(page, Math.min(9000, remaining()), state);
          sample.add_to_cart = addResult;

          if (addResult !== 'clicked') {
            continue;
          }

          await acceptPublicEntryOverlays(page);
          sample.cart_verified = remaining() > 3000 && await openCartAndVerify(page, state, audit, Math.min(12000, remaining()));
          sample.add_to_cart = sample.cart_verified ? 'works' : 'clicked_but_not_verified';

          if (sample.cart_verified) {
            audit.cart_result = 'product_present';
            break;
          }
        } catch (error) {
          sample.add_to_cart = 'error';
          sample.error = error instanceof StepOutcome ? error.errorCode : 'browser_error';
        }
      }

      if (audit.cart_result !== 'product_present') {
        audit.cart_result = audit.products_tested === 0 ? 'no_product_tested'
          : audit.sampled_products.every((sample) => sample.add_to_cart === 'options_required')
            ? 'product_options_unavailable' : 'add_to_cart_not_verified';
        if (remaining() < 3000) {
          throw new StepOutcome('inconclusive', 'infra_timeout', 'step_budget_exhausted');
        }

        throw UNSUPPORTED(audit.cart_result);
      }

      if (remaining() < 5000) {
        throw new StepOutcome('inconclusive', 'infra_timeout', 'step_budget_exhausted');
      }

      const checkoutReached = await proceedToCheckout(page, state, audit, Math.min(15000, remaining()));

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

export async function discoverInventory(page, state, timeout) {
  const deadline = Date.now() + timeout;
  const initial = await collectPublicLinks(page, state);
  const categories = unique(initial.filter((link) => looksLikeCategory(link)).map((link) => link.href)).slice(0, PRODUCT_DISCOVERY_LIMIT);
  const products = unique(initial.filter((link) => looksLikeProduct(link)).map((link) => link.href)).slice(0, PRODUCT_DISCOVERY_LIMIT);
  let cartUrl = initial.find(looksLikeCartLink)?.href ?? null;
  let checkoutUrl = initial.find(looksLikeCheckoutLink)?.href ?? null;

  const listingCandidates = unique(initial.filter(looksLikeListing).map((link) => link.href));
  const visited = new Set();

  for (let index = 0; index < listingCandidates.length && visited.size < CATEGORY_VISIT_LIMIT; index++) {
    const href = listingCandidates[index];

    if (visited.has(href)) {
      continue;
    }

    visited.add(href);

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

        if (looksLikeListing(link) && !visited.has(link.href) && !listingCandidates.includes(link.href)) {
          listingCandidates.push(link.href);
        }
      }

      cartUrl = cartUrl ?? links.find(looksLikeCartLink)?.href ?? null;
      checkoutUrl = checkoutUrl ?? links.find(looksLikeCheckoutLink)?.href ?? null;
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
        productCard: node.closest('.product-box, [data-product-information], li.product, .wc-block-grid__product') !== null,
      };
    } catch {
      return null;
    }
  }).filter((link) => link !== null && args.origins.includes(link.origin)), { origins }).catch(() => []);

  return uniqueBy(links.filter((link) => !BAD_DISCOVERY_PATH.test(link.pathname)), (link) => link.href);
}

function looksLikeProduct(link) {
  const haystack = `${link.href} ${link.text}`;

  if (CART_PATH_HINT.test(haystack) || CHECKOUT_PATH_HINT.test(haystack) || /\/product-tag\//i.test(link.href)) {
    return false;
  }

  return link.productCard || PRODUCT_PATH_HINT.test(link.pathname);
}

function looksLikeCategory(link) {
  const haystack = `${link.href} ${link.text}`;

  if (CART_PATH_HINT.test(haystack) || CHECKOUT_PATH_HINT.test(haystack) || PRODUCT_PATH_HINT.test(link.pathname) || /\/page\/\d+\/?$/i.test(link.pathname) || /^\/(?:shop|store|catalog|products?|produkte)\/?$/i.test(link.pathname)) {
    return false;
  }

  return CATEGORY_PATH_HINT.test(link.pathname) || /\b(category|collection|sortiment|kategorie)\b/i.test(link.text);
}

function looksLikeListing(link) {
  return looksLikeCategory(link) || /\/(?:shop|store|catalog|products?|produkte|collections?)(?:\/page\/\d+)?\/?$/i.test(link.pathname)
    || /\b(shop|catalog|sortiment|assortiment)\b/i.test(link.text);
}

function looksLikeCartLink(link) {
  return CART_PATH_HINT.test(link.pathname) && !ADD_TO_CART_HINT.test(link.href);
}

function looksLikeCheckoutLink(link) {
  return CHECKOUT_PATH_HINT.test(link.pathname) && !CART_PATH_HINT.test(link.pathname) && !/place[-_]?order|pay\b/i.test(link.pathname);
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
  if (!CART_PATH_HINT.test(new URL(page.url()).pathname)) {
    return false;
  }

  const hasLine = await page.locator('.woocommerce-cart-form__cart-item, .cart_item, .wc-block-cart-items__row, .line-item, [data-line-item-id], .cart-item').count().catch(() => 0);

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
    await followPublicLink(page, checkout, timeout, state, { checkoutLanding: true });
    await acceptPublicEntryOverlays(page);
  } catch {
    return false;
  }

  return await page.locator('input, textarea, select').evaluateAll((nodes) => nodes.some((node) => {
    if (!(node instanceof HTMLElement) || node.offsetParent === null || node.hasAttribute('disabled')) {
      return false;
    }

    const attributes = ['name', 'id', 'autocomplete', 'placeholder', 'aria-label']
      .map((name) => node.getAttribute(name) ?? '').join(' ');

    return /email|e-mail|password|passwort|first.?name|last.?name|billing|shipping|address|street|postcode|zip|phone|telefon/i.test(attributes);
  })).catch(() => false);
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

export async function acceptPublicEntryOverlays(page) {
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
          node.getAttribute('value') ?? '',
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
        return node.closest('[role="dialog"], [aria-modal="true"], dialog, .modal, .popup, .overlay, .cookie, .cookies, .consent, .age, .age-gate, .agegate, .verification, .privacy, .gdpr, #onetrust-banner-sdk, #CybotCookiebotDialog, [class*="cookie"], [class*="consent"], [class*="age-gate"], [class*="modal"], [class*="popup"]') !== null;
      }

      return nodes.findIndex((node) => {
        if (!(node instanceof HTMLElement) || node.offsetParent === null || node.hasAttribute('disabled') || !overlayLike(node)) {
          return false;
        }

        return consentAction(textOf(node)) === 'accept';
      });
    }, { accept: CONSENT_ACCEPT_HINT.source, reject: CONSENT_REJECT_HINT.source, dangerous: DANGEROUS_CONSENT_HINT.source }).catch(() => -1);

    if (index < 0) {
      if (attempt < 2 && await page.locator('[role="dialog"][aria-modal="true"], .age-gate, #onetrust-banner-sdk, #CybotCookiebotDialog').count().catch(() => 0) > 0) {
        await page.waitForTimeout(700);
        continue;
      }

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
  const links = await page.locator('a[href]').evaluateAll((nodes) => nodes.slice(0, 1200).map((node) => ({
    href: node instanceof HTMLAnchorElement ? node.href : null,
    text: (node.textContent ?? '').trim(),
    aria: node.getAttribute('aria-label') ?? '',
  }))).catch(() => []);

  for (const link of links) {
    if (link.href === null) {
      continue;
    }

    const pathname = new URL(link.href).pathname;

    if (hint === CHECKOUT_HINT ? looksLikeCheckoutLink({ pathname }) : looksLikeCartLink({ pathname, href: link.href })) {
      return link.href;
    }
  }

  return null;
}

async function followPublicLink(page, href, timeout, state, { checkoutLanding = false } = {}) {
  const target = new URL(href, state.scenario.store_origin);

  if (!allowedStoreOrigin(state, target.origin)) {
    throw new StepOutcome('blocked', 'blocked_external_origin', 'left_store_origin');
  }

  if (PRIVATE_PATH.test(target.pathname) && !(checkoutLanding && /\/(?:my-account|account|login|register)\/?$/i.test(target.pathname))) {
    throw UNSUPPORTED('login_required');
  }

  const response = await navigate(page, target.href, timeout, state);
  if (!checkoutLanding) {
    await assertNoPrivatePage(page);
  } else if (/\/admin|\/wp-admin|\/orders?\b/i.test(new URL(page.url()).pathname)) {
    throw UNSUPPORTED('login_required');
  }

  if (response && response.status() >= 400 && response.status() < 500) {
    throw SITE(`http_${response.status()}`);
  }
}

export async function tryPublicAddToCart(page, timeout, state) {
  const optionsReady = await selectPublicProductOptions(page, Math.min(timeout, 5000));
  if (!optionsReady) {
    return 'options_required';
  }

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
    return 'button_not_found';
  }

  const candidate = page.locator('a[href], button, input[type="submit"], input[type="button"]').nth(index);
  const before = page.url();
  try {
    await candidate.click({ timeout: Math.min(timeout, 4000) });
  } catch (error) {
    if (error?.name !== 'TimeoutError' || await page.locator('[role="dialog"][aria-modal="true"]:visible, .modal.show:visible').count().catch(() => 0) === 0) {
      throw error;
    }

    await acceptPublicEntryOverlays(page);
    await candidate.click({ timeout: Math.min(timeout, 4000) });
  }
  await Promise.race([
    page.waitForURL((url) => url.href !== before, { timeout: Math.min(timeout, 8000) }),
    page.waitForLoadState('networkidle', { timeout: Math.min(timeout, 8000) }),
    page.waitForTimeout(1500),
  ]).catch(() => null);
  await page.waitForLoadState('domcontentloaded', { timeout: 3000 }).catch(() => null);

  return 'clicked';
}

async function selectPublicProductOptions(page, timeout) {
  const form = page.locator('form.variations_form, form.cart').first();
  if (await form.count() === 0) {
    return true;
  }

  // Only built-in product-choice controls are filled. Text, upload, account and
  // customer fields are never touched on an unverified storefront.
  const selects = form.locator('select[name^="attribute_"], .variations select, select[required]');
  const count = Math.min(await selects.count(), 5);
  for (let index = 0; index < count; index++) {
    const select = selects.nth(index);
    // WooCommerce themes commonly hide the native variation select behind a
    // visible swatch. Changing that native select still dispatches its event.
    if (!await select.isVisible().catch(() => false) && !await select.getAttribute('name').then((name) => name?.startsWith('attribute_')).catch(() => false)) {
      continue;
    }

    const value = await select.evaluate((node) => {
      const selected = node.selectedOptions[0];
      if (selected?.value && !selected.disabled) {
        return selected.value;
      }
      return Array.from(node.options).find((option) => option.value && !option.disabled && !option.closest('optgroup[disabled]'))?.value ?? null;
    }).catch(() => null);
    if (value === null) {
      return false;
    }
    await select.selectOption(value, { timeout: Math.max(1000, timeout), force: true });
  }

  const requiredChoiceMissing = await form.evaluate((node) => Array.from(node.querySelectorAll('select[required], select[name^="attribute_"]'))
    .some((select) => !select.disabled && (select.offsetParent !== null || select.name.startsWith('attribute_')) && !select.value)).catch(() => false);
  if (requiredChoiceMissing) {
    return false;
  }

  const button = form.locator('button.single_add_to_cart_button, button[name="add-to-cart"], input[name="add-to-cart"]').first();
  if (await button.count() > 0) {
    await button.waitFor({ state: 'visible', timeout: Math.min(timeout, 2500) }).catch(() => null);
    for (let attempt = 0; attempt < 5; attempt++) {
      if (await button.isEnabled().catch(() => false) && !await button.evaluate((node) => node.classList.contains('disabled')).catch(() => true)) {
        return true;
      }
      await page.waitForTimeout(250);
    }
    return false;
  }

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
