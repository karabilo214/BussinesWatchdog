export const ADAPTER_VERSION = 'woocommerce-payment-form@1';

export class StepOutcome extends Error {
  constructor(status, errorCode, detailCode) {
    super(detailCode);
    this.status = status;
    this.errorCode = errorCode;
    this.detailCode = detailCode;
  }
}

const SITE = (detail) => new StepOutcome('failed', 'site_failure', detail);
const PRODUCT = (detail) => new StepOutcome('failed', 'product_unavailable', detail);
const UNSUPPORTED = (detail) => new StepOutcome('unsupported', 'adapter_unsupported', detail);

const SELECTORS = {
  wooPage: 'body.woocommerce, body.woocommerce-page, .woocommerce, .wp-block-woocommerce-checkout, .wp-block-woocommerce-cart',
  addToCart: 'form.cart button[name="add-to-cart"], form.cart button.single_add_to_cart_button, form.cart .single_add_to_cart_button',
  outOfStock: '.stock.out-of-stock, .wc-block-components-product-stock-indicator--out-of-stock',
  wooError: '.woocommerce-error, .wc-block-components-notice-banner.is-error',
  classicCartItem: '.woocommerce-cart-form .cart_item',
  blocksCartItem: '.wc-block-cart-items__row, .wc-block-cart-item',
  emptyCart: '.cart-empty, .wc-block-cart__empty-cart__title, .wp-block-woocommerce-empty-cart-block',
  classicCheckout: 'form.checkout.woocommerce-checkout',
  blocksCheckout: '.wc-block-checkout, .wp-block-woocommerce-checkout .wc-block-components-form',
  classicPaymentMethod: '#payment ul.payment_methods li input[name="payment_method"], #payment ul.payment_methods li.wc_payment_method',
  classicNoPaymentMethods: '#payment .woocommerce-notice--info, #payment li.woocommerce-notice',
  blocksPaymentMethod: '.wc-block-checkout__payment-method .wc-block-components-radio-control__option, .wc-block-checkout__payment-method .wc-block-components-payment-method-label, .wc-block-checkout__payment-method .wc-block-components-checkout-step__content > *',
  blocksNoPaymentMethods: '.wc-block-checkout__no-payment-methods-notice, .wc-block-checkout__payment-method .wc-block-components-notice-banner.is-error',
};

async function visible(page, selector, timeout) {
  try {
    await page.locator(selector).first().waitFor({ state: 'visible', timeout });

    return true;
  } catch {
    return false;
  }
}

async function present(page, selector) {
  return (await page.locator(selector).count()) > 0;
}

async function navigate(page, url, timeout, context) {
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

export function createWooCommerceAdapter() {
  return {
    async product(page, step, context) {
      const response = await navigate(page, context.scenario.product_url, step.timeout_ms, context);

      if (response && (response.status() === 404 || response.status() === 410)) {
        throw PRODUCT(`http_${response.status()}`);
      }

      if (await visible(page, SELECTORS.addToCart, step.timeout_ms)) {
        const button = page.locator(SELECTORS.addToCart).first();

        if (await button.isDisabled()) {
          throw PRODUCT('add_to_cart_disabled');
        }

        return [{ code: 'add_to_cart_ready', passed: true }];
      }

      if (await present(page, SELECTORS.outOfStock)) {
        throw PRODUCT('out_of_stock');
      }

      if (await present(page, SELECTORS.wooPage)) {
        throw new StepOutcome('inconclusive', 'selector_changed', 'add_to_cart_not_found');
      }

      throw UNSUPPORTED('not_a_woocommerce_product_page');
    },

    async add_to_cart(page, step, context) {
      const button = page.locator(SELECTORS.addToCart).first();

      await button.click({ timeout: step.timeout_ms });
      await page.waitForLoadState('domcontentloaded', { timeout: step.timeout_ms }).catch(() => null);
      await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => null);

      if (context.forbiddenOperation) {
        throw new StepOutcome('blocked', 'forbidden_mutation', context.forbiddenOperation);
      }

      if (await present(page, SELECTORS.wooError)) {
        throw SITE('add_to_cart_error_notice');
      }

      return [{ code: 'add_to_cart_submitted', passed: true }];
    },

    async cart(page, step, context) {
      await navigate(page, context.scenario.cart_url, step.timeout_ms, context);

      const found = await Promise.any([
        page.locator(SELECTORS.classicCartItem).first().waitFor({ state: 'visible', timeout: step.timeout_ms }).then(() => 'item'),
        page.locator(SELECTORS.blocksCartItem).first().waitFor({ state: 'visible', timeout: step.timeout_ms }).then(() => 'item'),
        page.locator(SELECTORS.emptyCart).first().waitFor({ state: 'visible', timeout: step.timeout_ms }).then(() => 'empty'),
      ]).catch(() => null);

      if (found === 'item') {
        return [{ code: 'cart_has_line', passed: true }];
      }

      if (found === 'empty') {
        throw SITE('cart_empty_after_add');
      }

      if (await present(page, SELECTORS.wooPage)) {
        throw new StepOutcome('inconclusive', 'selector_changed', 'cart_not_recognised');
      }

      throw UNSUPPORTED('not_a_woocommerce_cart_page');
    },

    async checkout(page, step, context) {
      await navigate(page, context.scenario.checkout_url, step.timeout_ms, context);

      const mode = await Promise.any([
        page.locator(SELECTORS.classicCheckout).first().waitFor({ state: 'visible', timeout: step.timeout_ms }).then(() => 'classic'),
        page.locator(SELECTORS.blocksCheckout).first().waitFor({ state: 'visible', timeout: step.timeout_ms }).then(() => 'blocks'),
        page.locator(SELECTORS.emptyCart).first().waitFor({ state: 'visible', timeout: step.timeout_ms }).then(() => 'empty'),
      ]).catch(() => null);

      if (mode === 'classic' || mode === 'blocks') {
        context.checkoutMode = mode;

        return [{ code: 'checkout_rendered', passed: true, detail_code: mode }];
      }

      if (mode === 'empty') {
        throw SITE('checkout_cart_empty');
      }

      if (await present(page, SELECTORS.wooError)) {
        throw SITE('checkout_error_notice');
      }

      if (await present(page, SELECTORS.wooPage)) {
        throw SITE('checkout_form_missing');
      }

      throw UNSUPPORTED('not_a_woocommerce_checkout_page');
    },

    async shipping(page, step, context) {
      const location = context.scenario.synthetic_location;

      if (!location) {
        return { skipped: true, assertions: [{ code: 'synthetic_location_not_configured', passed: true }] };
      }

      if (context.checkoutMode === 'classic') {
        await page.evaluate(({ country, postcode }) => {
          const select = document.querySelector('#billing_country, #shipping_country');

          if (select) {
            select.value = country;
            select.dispatchEvent(new Event('change', { bubbles: true }));

            if (window.jQuery) {
              window.jQuery(select).trigger('change');
            }
          }

          const field = document.querySelector('#billing_postcode, #shipping_postcode');

          if (field && postcode) {
            field.value = postcode;
            field.dispatchEvent(new Event('change', { bubbles: true }));
          }
        }, location);
      } else {
        const country = page.locator('select#shipping-country, select#billing-country').first();

        if (await country.count()) {
          await country.selectOption(location.country, { timeout: step.timeout_ms }).catch(() => null);
        }

        const postcode = page.locator('#shipping-postcode, #billing-postcode').first();

        if (location.postcode && (await postcode.count())) {
          await postcode.fill(location.postcode, { timeout: step.timeout_ms }).catch(() => null);
        }
      }

      await page.waitForLoadState('networkidle', { timeout: 3000 }).catch(() => null);

      if (context.forbiddenOperation) {
        throw new StepOutcome('blocked', 'forbidden_mutation', context.forbiddenOperation);
      }

      return [{ code: 'synthetic_location_filled', passed: true }];
    },

    async payment_form(page, step, context) {
      const blocks = context.checkoutMode === 'blocks';
      const methods = blocks ? SELECTORS.blocksPaymentMethod : SELECTORS.classicPaymentMethod;
      const none = blocks ? SELECTORS.blocksNoPaymentMethods : SELECTORS.classicNoPaymentMethods;

      const outcome = await Promise.any([
        page.locator(methods).first().waitFor({ state: 'attached', timeout: step.timeout_ms }).then(() => 'methods'),
        page.locator(none).first().waitFor({ state: 'visible', timeout: step.timeout_ms }).then(() => 'none'),
      ]).catch(() => null);

      if (outcome === 'methods' && !(await present(page, none))) {
        return [{ code: 'payment_method_available', passed: true, detail_code: context.checkoutMode }];
      }

      throw SITE(outcome === 'none' ? 'no_payment_methods' : 'payment_form_missing');
    },
  };
}
