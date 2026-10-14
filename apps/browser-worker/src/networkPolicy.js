const READ_METHODS = new Set(['GET', 'HEAD', 'OPTIONS']);

const ALLOWED_WC_AJAX = new Set([
  'add_to_cart',
  'get_refreshed_fragments',
  'update_order_review',
  'update_shipping_method',
  'get_variation',
]);

const STORE_API = /^\/wc\/store(?:\/v\d+)?\/(.*)$/;

const PAYMENT_OPERATIONS = new Set([
  'place_order',
  'classic_checkout_submit',
  'store_api_checkout_submit',
  'order_pay_submit',
  'payment_capture',
  'refund',
]);

const SHOPWARE_ROUTE = /^(?:\/[^/]+)*?\/(checkout|account|payment|store-api)(\/.*)?$/;

const SHOPWARE_CART = /^\/(line-item\/add|line-item\/delete\/[0-9a-f]{32}|configure)\/?$/;

/** Lookups the storefront sends as POST without changing anything (6.6: regions of the chosen country). */
const SHOPWARE_READ_POSTS = /\/country\/country-state-data\/?$/;

const TRUE_VALUES = new Set(['1', 'true', 'on', 'yes']);

const GATEWAY_MONEY_PATH = /(capture|confirm|charges|refunds?|payment_intents\/[^/]+\/(confirm|capture)|orders\/[^/]+\/(capture|authorize))/i;

/**
 * Field names and values of a submitted form, for both urlencoded and multipart bodies
 * (WooCommerce's add-to-cart form is multipart/form-data).
 */
export function formFields(postData) {
  const fields = new Map();

  if (typeof postData !== 'string' || postData === '') {
    return fields;
  }

  if (postData.startsWith('--')) {
    const part = /Content-Disposition:\s*form-data;\s*name="([^"]+)"(?:;[^\r\n]*)?\r?\n(?:[^\r\n]+\r?\n)*\r?\n([^\r\n]*)/gi;
    let match;

    while ((match = part.exec(postData)) !== null) {
      fields.set(match[1], match[2]);
    }

    return fields;
  }

  try {
    for (const [key, value] of new URLSearchParams(postData)) {
      fields.set(key, value);
    }
  } catch {
    return fields;
  }

  return fields;
}

export function originOf(url) {
  const parsed = new URL(url);

  return `${parsed.protocol}//${parsed.host}`.toLowerCase();
}

export function restRoute(parsed) {
  const fromQuery = parsed.searchParams.get('rest_route');

  if (fromQuery) {
    return fromQuery;
  }

  const index = parsed.pathname.indexOf('/wp-json/');

  return index === -1 ? null : parsed.pathname.slice(index + '/wp-json'.length);
}

/**
 * Decides whether the browser may send a request. Read requests are allowed to allowed
 * origins only; any mutation must match an explicit allowlist of cart operations, so an
 * unknown mutation is blocked rather than trusted. Order placement, order-pay, capture and
 * refund are always blocked, whatever the button text.
 */
export function createNetworkPolicy({ allowedOrigins, storeOrigin, insecureLocal = false, platform = 'woocommerce', checkoutAccess = null, quietOrigins = [] }) {
  const allowed = new Set(allowedOrigins.map((origin) => origin.toLowerCase().replace(/\/$/, '')));
  const store = storeOrigin.toLowerCase().replace(/\/$/, '');
  const publicStoreOrigins = platform === 'public' ? allowed : new Set([store]);
  const quiet = new Set(quietOrigins.map((origin) => origin.toLowerCase().replace(/\/$/, '')));

  function originAllowed(parsed) {
    if (parsed.protocol !== 'https:' && !(insecureLocal && parsed.protocol === 'http:')) {
      return false;
    }

    if (parsed.port !== '' && !insecureLocal) {
      return false;
    }

    return allowed.has(`${parsed.protocol}//${parsed.host}`.toLowerCase());
  }

  function classifyStoreMutation(parsed, method, postData) {
    const wcAjax = parsed.searchParams.get('wc-ajax');

    if (wcAjax !== null) {
      if (wcAjax === 'checkout') {
        return 'classic_checkout_submit';
      }

      return ALLOWED_WC_AJAX.has(wcAjax) ? null : 'unknown_mutation';
    }

    if (/order-pay|pay_for_order/.test(parsed.pathname + parsed.search)) {
      return 'order_pay_submit';
    }

    const route = restRoute(parsed);
    const storeApi = route === null ? null : STORE_API.exec(route);

    if (storeApi !== null) {
      const endpoint = storeApi[1];

      if (/^checkout\/\d+/.test(endpoint)) {
        return 'order_pay_submit';
      }

      if (endpoint === 'checkout' || endpoint === 'checkout/') {
        return method === 'PUT' ? null : 'store_api_checkout_submit';
      }

      if (endpoint === 'batch') {
        return classifyBatch(postData);
      }

      return /^cart(\/|$)/.test(endpoint) ? null : 'unknown_mutation';
    }

    const fields = formFields(postData);

    if (fields.has('woocommerce-process-checkout-nonce') || fields.has('place_order') || fields.has('woocommerce-pay-nonce')) {
      return fields.has('woocommerce-pay-nonce') ? 'order_pay_submit' : 'classic_checkout_submit';
    }

    if (method === 'POST' && /^\d+$/.test(fields.get('add-to-cart') ?? '')) {
      return null;
    }

    return 'unknown_mutation';
  }

  /**
   * Shopware storefront (ADR 0055): cart operations are allowed; signing in only for the test customer way and
   * registering only as a guest (no account, no password) for the guest way. Placing the order, paying an existing
   * order and the payment routes are always blocked; any other mutation is unknown and blocked.
   */
  function classifyShopwareMutation(parsed, postData) {
    if (SHOPWARE_READ_POSTS.test(parsed.pathname)) {
      return null;
    }

    const match = SHOPWARE_ROUTE.exec(parsed.pathname);

    if (match === null) {
      return 'unknown_mutation';
    }

    const [, area, rest = ''] = match;

    if (area === 'payment') {
      return 'payment_capture';
    }

    if (area === 'store-api') {
      if (/^\/checkout\/order\/?$/.test(rest)) {
        return 'place_order';
      }

      return /^\/handle-payment/.test(rest) ? 'payment_capture' : 'unknown_mutation';
    }

    if (area === 'checkout') {
      if (/^\/order\/?$/.test(rest)) {
        return 'place_order';
      }

      return SHOPWARE_CART.test(rest) ? null : 'unknown_mutation';
    }

    if (/^\/order(\/|$)/.test(rest)) {
      return 'order_pay_submit';
    }

    if (/^\/login\/?$/.test(rest)) {
      return checkoutAccess === 'test_customer' ? null : 'unknown_mutation';
    }

    if (/^\/register\/?$/.test(rest)) {
      if (checkoutAccess !== 'guest') {
        return 'unknown_mutation';
      }

      const fields = formFields(postData);

      if (TRUE_VALUES.has(String(fields.get('createCustomerAccount') ?? '').toLowerCase()) || (fields.get('password') ?? '') !== '') {
        return 'customer_account_creation';
      }

      return null;
    }

    return 'unknown_mutation';
  }

  function classifyBatch(postData) {
    try {
      const body = JSON.parse(postData ?? '');
      const requests = Array.isArray(body?.requests) ? body.requests : null;

      if (requests === null || requests.length === 0) {
        return 'unknown_mutation';
      }

      for (const inner of requests) {
        const match = STORE_API.exec(String(inner?.path ?? '').split('?')[0]);

        if (match === null) {
          return 'unknown_mutation';
        }

        if (/^checkout/.test(match[1])) {
          return 'store_api_checkout_submit';
        }

        if (!/^cart(\/|$)/.test(match[1])) {
          return 'unknown_mutation';
        }
      }

      return null;
    } catch {
      return 'unknown_mutation';
    }
  }

  function classifyPublicMutation(parsed, method, postData) {
    if (!publicStoreOrigins.has(`${parsed.protocol}//${parsed.host}`.toLowerCase())) {
      return 'unknown_mutation';
    }

    if (platform !== 'public') {
      return 'unknown_mutation';
    }

    if (/^\/cdn-cgi\/(?:rum\/?$|challenge-platform\/)/.test(parsed.pathname)
      || /^\/cookie-consent\/(?:accept|allow-all|allow-cookie-group|allow-only-functional)\/?$/.test(parsed.pathname)) {
      return null;
    }

    const route = restRoute(parsed);
    const storeApi = route === null ? null : STORE_API.exec(route);

    if (storeApi !== null && storeApi[1] === 'batch') {
      return classifyPublicBatch(postData);
    }

    const shopware = classifyShopwareMutation(parsed, postData);

    if (shopware === null && /\/checkout\/line-item\/add\/?$/.test(parsed.pathname)) {
      return null;
    }

    const wc = classifyStoreMutation(parsed, method, postData);

    if (wc === null) {
      const wcAjax = parsed.searchParams.get('wc-ajax');
      const fields = formFields(postData);

      if (wcAjax === 'add_to_cart' || (storeApi !== null && /^cart\/add-item\/?$/.test(storeApi[1])) || fields.has('add-to-cart')) {
        return null;
      }
    }

    return 'forbidden_mutation';
  }

  function classifyPublicBatch(postData) {
    try {
      const requests = JSON.parse(postData ?? '')?.requests;

      if (!Array.isArray(requests) || requests.length === 0 || requests.length > 20) {
        return 'forbidden_mutation';
      }

      for (const inner of requests) {
        const path = String(inner?.path ?? '').split('?')[0];
        const endpoint = STORE_API.exec(path)?.[1];
        const verb = String(inner?.method ?? '').toUpperCase();

        if (endpoint === undefined || !(
          (READ_METHODS.has(verb) && /^(?:cart|checkout|products)(?:\/|$)/.test(endpoint))
          || (verb === 'POST' && /^cart\/add-item\/?$/.test(endpoint))
        )) {
          return 'forbidden_mutation';
        }
      }

      return null;
    } catch {
      return 'forbidden_mutation';
    }
  }

  return {
    /**
     * @returns {{ allow: boolean, reason?: 'scheme'|'origin_not_allowed'|'mutation', operation?: string }}
     */
    decide({ url, method, postData = null, isNavigation = false }) {
      let parsed;

      try {
        parsed = new URL(url);
      } catch {
        return { allow: false, reason: 'origin_not_allowed' };
      }

      if (['data:', 'blob:', 'about:'].includes(parsed.protocol)) {
        return { allow: true };
      }

      if (quiet.has(`${parsed.protocol}//${parsed.host}`.toLowerCase())) {
        return { allow: false, reason: 'quiet' };
      }

      const verb = String(method).toUpperCase();
      const publicExternalSubrequest = platform === 'public'
        && !isNavigation
        && (parsed.protocol === 'https:' || (insecureLocal && parsed.protocol === 'http:'))
        && (parsed.port === '' || insecureLocal)
        && !allowed.has(`${parsed.protocol}//${parsed.host}`.toLowerCase());

      if (!originAllowed(parsed) && !publicExternalSubrequest) {
        return { allow: false, reason: parsed.protocol === 'https:' || parsed.protocol === 'http:' ? 'origin_not_allowed' : 'scheme' };
      }

      const onStore = `${parsed.protocol}//${parsed.host}`.toLowerCase() === store;

      if (onStore && platform === 'shopware' && SHOPWARE_ROUTE.exec(parsed.pathname)?.[1] === 'payment') {
        return { allow: false, reason: 'mutation', operation: 'payment_capture' };
      }

      if (publicExternalSubrequest && GATEWAY_MONEY_PATH.test(parsed.pathname)) {
        return { allow: false, reason: 'mutation', operation: 'payment_capture' };
      }

      if (READ_METHODS.has(verb)) {
        return { allow: true };
      }

      if (platform === 'public') {
        if (publicExternalSubrequest) {
          return { allow: true };
        }

        const operation = classifyPublicMutation(parsed, verb, postData);

        return operation === null ? { allow: true } : { allow: false, reason: 'mutation', operation };
      }

      if (onStore) {
        const operation = platform === 'shopware' ? classifyShopwareMutation(parsed, postData) : classifyStoreMutation(parsed, verb, postData);

        return operation === null ? { allow: true } : { allow: false, reason: 'mutation', operation };
      }

      if (GATEWAY_MONEY_PATH.test(parsed.pathname)) {
        return { allow: false, reason: 'mutation', operation: 'payment_capture' };
      }

      return { allow: true };
    },

    isPaymentOperation(operation) {
      return PAYMENT_OPERATIONS.has(operation);
    },

    party(url) {
      try {
        const origin = originOf(url);

        if (origin === store || (platform === 'public' && publicStoreOrigins.has(origin))) {
          return 'first';
        }

        return allowed.has(origin) ? 'gateway' : 'third';
      } catch {
        return 'third';
      }
    },
  };
}
