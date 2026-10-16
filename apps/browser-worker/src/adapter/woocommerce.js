import { PRODUCT, SITE, StepOutcome, UNSUPPORTED, navigate, present, visible } from './common.js';
import { displayedMinor, sameProductPath } from './cartAssertions.js';

export { StepOutcome };

export const ADAPTER_VERSION = 'woocommerce-payment-form@1';

const SELECTORS = {
  wooPage: 'body.woocommerce, body.woocommerce-page, .woocommerce, .wp-block-woocommerce-checkout, .wp-block-woocommerce-cart',
  addToCart: 'form.cart button[name="add-to-cart"], form.cart button.single_add_to_cart_button, form.cart .single_add_to_cart_button',
  outOfStock: '.stock.out-of-stock, .wc-block-components-product-stock-indicator--out-of-stock',
  wooError: '.woocommerce-error, .wc-block-components-notice-banner.is-error',
  classicCartItem: '.woocommerce-cart-form .cart_item',
  blocksCartItem: '.wc-block-cart-items__row, .wc-block-cart-item',
  classicLineAmount: '.product-subtotal .woocommerce-Price-amount, .product-subtotal .amount',
  blocksLineAmount: '.wc-block-cart-item__total .wc-block-formatted-money-amount',
  classicCartTotal: '.cart_totals .order-total .woocommerce-Price-amount, .cart_totals .order-total .amount',
  blocksCartTotal: '.wc-block-components-totals-footer-item .wc-block-formatted-money-amount',
  checkoutControl: 'a.checkout-button:visible, .wc-block-cart__submit-button:visible, .wc-block-cart__submit a:visible',
  emptyCart: '.cart-empty, .wc-block-cart__empty-cart__title, .wp-block-woocommerce-empty-cart-block',
  classicCheckout: 'form.checkout.woocommerce-checkout',
  blocksCheckout: '.wc-block-checkout, .wp-block-woocommerce-checkout .wc-block-components-form',
  classicPaymentMethod: '#payment ul.payment_methods li input[name="payment_method"], #payment ul.payment_methods li.wc_payment_method',
  classicNoPaymentMethods: '#payment .woocommerce-notice--info, #payment li.woocommerce-notice, #payment .woocommerce-info',
  blocksPaymentMethod: '.wc-block-checkout__payment-method .wc-block-components-radio-control__option, .wc-block-checkout__payment-method .wc-block-components-payment-method-label, .wc-block-checkout__payment-method .wc-block-components-checkout-step__content > *',
  blocksNoPaymentMethods: '.wc-block-checkout__no-payment-methods-notice, .wc-block-checkout__payment-method .wc-block-components-notice-banner.is-error',
  shippingMethods: 'input[name^="shipping_method"]:not([type="hidden"]), .wc-block-components-shipping-rates-control input[type="radio"]',
};

async function selectableInput(inputs, timeout) {
  for (let index = 0; index < await inputs.count(); index++) {
    const input = inputs.nth(index);
    if (!await input.isEnabled()) continue;
    if (await input.isChecked()) return true;
    if (!await input.isVisible()) continue;
    await input.check({ timeout: Math.min(timeout, 5000) }).catch(() => null);
    if (await input.isChecked().catch(() => false)) return true;
  }
  return false;
}

async function selectProductOptions(page, timeout) {
  const form = page.locator('form.cart').first();
  const selects = form.locator('select[name^="attribute_"]');
  const count = await selects.count();

  for (let index = 0; index < count; index++) {
    const select = selects.nth(index);
    const value = await select.evaluate((node) => {
      const selected = node.selectedOptions[0];
      return selected?.value && !selected.disabled ? selected.value
        : Array.from(node.options).find((option) => option.value && !option.disabled && !option.closest('optgroup[disabled]'))?.value ?? null;
    });

    if (value === null) {
      throw PRODUCT('variation_unavailable');
    }

    await select.selectOption(value, { timeout, force: true });
  }

  if (count > 0) {
    const button = page.locator(SELECTORS.addToCart).first();
    for (let attempt = 0; attempt < 5; attempt++) {
      if (await button.isEnabled() && !await button.evaluate((node) => node.classList.contains('disabled'))) {
        return;
      }
      await page.waitForTimeout(250);
    }
    throw PRODUCT('variation_unavailable');
  }
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
          throw await present(page, SELECTORS.outOfStock) ? PRODUCT('add_to_cart_disabled') : SITE('add_to_cart_disabled_without_stock_notice');
        }

        context.productPath = page.url();
        context.productCanonical = await page.locator('link[rel="canonical"]').first().getAttribute('href', { timeout: 1000 }).catch(() => null);
        context.productId = await button.getAttribute('value');

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

      await selectProductOptions(page, step.timeout_ms);
      context.selectedOptions = await page.locator('form.cart select[name^="attribute_"]').evaluateAll((selects) => selects.map((select) => select.selectedOptions[0]?.textContent?.trim().toLowerCase()).filter(Boolean));

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
        page.locator(`${SELECTORS.classicCartItem}:visible`).first().waitFor({ state: 'visible', timeout: step.timeout_ms }).then(() => 'item'),
        page.locator(`${SELECTORS.blocksCartItem}:visible`).first().waitFor({ state: 'visible', timeout: step.timeout_ms }).then(() => 'item'),
        page.locator(SELECTORS.emptyCart).first().waitFor({ state: 'visible', timeout: step.timeout_ms }).then(() => 'empty'),
      ]).catch(() => null);

      if (found === 'item') {
        const rows = page.locator(`${SELECTORS.classicCartItem}:visible, ${SELECTORS.blocksCartItem}:visible`);
        let expectedRow = null;
        const until = Date.now() + Math.min(step.timeout_ms, 5000);
        do {
          for (let index = 0; index < await rows.count(); index++) {
            const row = rows.nth(index);
            const links = await row.locator('a[href]').evaluateAll((anchors) => anchors.map((anchor) => anchor.href));
            const cartProductId = await row.evaluate((element) => element.querySelector('a.remove[data-product_id], a.remove_from_cart_button[data-product_id]')?.getAttribute('data-product_id') ?? null);
            if (links.some((link) => sameProductPath(context.productPath, link) || (context.productCanonical && sameProductPath(new URL(context.productCanonical, context.productPath).href, link))) || (context.productId && cartProductId === context.productId)) {
              expectedRow = row;
              break;
            }
          }
          if (!expectedRow) await page.waitForTimeout(250);
        } while (!expectedRow && Date.now() < until);
        if (!expectedRow) throw SITE('cart_expected_product_missing');
        const rowText = (await expectedRow.innerText()).toLowerCase();
        if ((context.selectedOptions ?? []).some((option) => !rowText.includes(option))) throw SITE('cart_expected_variant_missing');

        const blocks = await expectedRow.evaluate((element) => element.matches('.wc-block-cart-items__row, .wc-block-cart-item'));
        const lineText = await expectedRow.locator(blocks ? SELECTORS.blocksLineAmount : SELECTORS.classicLineAmount).first().textContent().catch(() => null);
        const totalText = await page.locator(blocks ? SELECTORS.blocksCartTotal : SELECTORS.classicCartTotal).first().textContent().catch(() => null);
        const lineMinor = displayedMinor(lineText);
        const totalMinor = displayedMinor(totalText);
        if (lineMinor === null || totalMinor === null) throw new StepOutcome('inconclusive', 'selector_changed', 'cart_amount_not_readable');
        if (lineMinor <= 0n || totalMinor <= 0n) throw SITE('cart_amount_not_positive');

        const checkout = page.locator(SELECTORS.checkoutControl).first();
        if (await checkout.count() === 0) {
          throw new StepOutcome('inconclusive', 'selector_changed', 'cart_checkout_control_not_found');
        }
        if (await checkout.isDisabled() || await checkout.getAttribute('aria-disabled') === 'true') {
          throw SITE('cart_checkout_control_disabled');
        }
        const before = page.url();
        await checkout.click({ timeout: Math.min(step.timeout_ms, 5000) }).catch(() => { throw SITE('cart_checkout_control_not_clickable'); });
        const reached = await page.waitForURL((url) => url.href !== before, { timeout: Math.min(step.timeout_ms, 4000) }).then(() => true).catch(() => false);
        if (!reached) {
          throw SITE('cart_checkout_control_no_navigation');
        }
        return [{ code: 'cart_expected_product', passed: true }, { code: 'cart_positive_amount', passed: true }, { code: 'checkout_control_works', passed: true }];
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

      const methods = page.locator(SELECTORS.shippingMethods);
      if (await methods.count() === 0) {
        if (await present(page, '.woocommerce-shipping-methods, .wc-block-components-shipping-rates-control, tr.shipping')) {
          throw SITE('no_shipping_methods');
        }
        throw new StepOutcome('inconclusive', 'selector_changed', 'shipping_not_exposed_or_virtual_product');
      }
      const selectable = await selectableInput(methods, step.timeout_ms);
      if (context.forbiddenOperation) throw new StepOutcome('blocked', 'forbidden_mutation', context.forbiddenOperation);
      if (context.blockedUnknownMutation) throw UNSUPPORTED('shipping_selection_blocked');
      if (!selectable) throw SITE('shipping_methods_unselectable');

      return [{ code: 'synthetic_location_filled', passed: true }, { code: 'delivery_option_selectable', passed: true }];
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
        const inputs = page.locator(blocks ? '.wc-block-checkout__payment-method input[type="radio"]' : '#payment input[name="payment_method"]');
        if (await inputs.count() === 0) throw new StepOutcome('inconclusive', 'selector_changed', 'payment_method_input_not_found');
        const selectable = await selectableInput(inputs, step.timeout_ms);
        if (context.forbiddenOperation) throw new StepOutcome('blocked', 'forbidden_mutation', context.forbiddenOperation);
        if (context.blockedUnknownMutation) throw UNSUPPORTED('payment_selection_blocked');
        if (!selectable) throw SITE('payment_methods_unselectable');
        const assertions = [{ code: 'payment_method_selectable', passed: true, detail_code: context.checkoutMode }];

        if (context.scenario.expect_checkout_script) {
          const script = await page.evaluate(() => window.BusinessWatchdogCheckout?.page ?? null).catch(() => null);
          assertions.push({ code: 'checkout_script_present', passed: script === 'checkout', detail_code: script === null ? 'script_missing' : `page_${script}` });
        }

        return assertions;
      }

      throw SITE(outcome === 'none' ? 'no_payment_methods' : 'payment_form_missing');
    },
  };
}
