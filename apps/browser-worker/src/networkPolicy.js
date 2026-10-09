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
  'classic_checkout_submit',
  'store_api_checkout_submit',
  'order_pay_submit',
  'payment_capture',
  'refund',
]);

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
export function createNetworkPolicy({ allowedOrigins, storeOrigin, insecureLocal = false }) {
  const allowed = new Set(allowedOrigins.map((origin) => origin.toLowerCase().replace(/\/$/, '')));
  const store = storeOrigin.toLowerCase().replace(/\/$/, '');

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

  return {
    /**
     * @returns {{ allow: boolean, reason?: 'scheme'|'origin_not_allowed'|'mutation', operation?: string }}
     */
    decide({ url, method, postData = null }) {
      let parsed;

      try {
        parsed = new URL(url);
      } catch {
        return { allow: false, reason: 'origin_not_allowed' };
      }

      if (['data:', 'blob:', 'about:'].includes(parsed.protocol)) {
        return { allow: true };
      }

      if (!originAllowed(parsed)) {
        return { allow: false, reason: parsed.protocol === 'https:' || parsed.protocol === 'http:' ? 'origin_not_allowed' : 'scheme' };
      }

      const verb = String(method).toUpperCase();

      if (READ_METHODS.has(verb)) {
        return { allow: true };
      }

      if (`${parsed.protocol}//${parsed.host}`.toLowerCase() === store) {
        const operation = classifyStoreMutation(parsed, verb, postData);

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

        if (origin === store) {
          return 'first';
        }

        return allowed.has(origin) ? 'gateway' : 'third';
      } catch {
        return 'third';
      }
    },
  };
}
