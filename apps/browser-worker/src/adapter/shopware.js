import { PRODUCT, SITE, StepOutcome, UNSUPPORTED, navigate, present, visible } from './common.js';
import { displayedMinor, sameProductPath } from './cartAssertions.js';

export const ADAPTER_VERSION = 'shopware-payment-form@1';

/**
 * Synthetic guest (ADR 0055): recognisable, not a real person, on a reserved domain.
 */
export const SYNTHETIC_GUEST = {
  firstName: 'Watchdog',
  lastName: 'Check',
  email: 'watchdog-check@example.com',
  street: 'Teststraße 1',
  city: 'Berlin',
  postcode: '10115',
};

const SELECTORS = {
  storefront: 'body[class*="is-ctl-"]',
  productPage: 'body.is-ctl-product',
  buyButton: 'form.buy-widget button.btn-buy, .product-detail-buy button.btn-buy',
  soldOut: '.delivery-soldout, .product-detail-buy .delivery-status-indicator.bg-danger',
  cartPage: 'body.is-act-cartpage',
  cartLine: '.line-item-product, .line-item.is-product',
  cartTotal: '.checkout-aside-summary-value.checkout-aside-summary-total',
  cartLineTotal: '.line-item-total-price-value',
  checkoutControl: 'a.begin-checkout-btn:visible',
  offcanvasError: '.offcanvas .alert-danger, .offcanvas-cart .alert-danger',
  registerPage: 'body.is-act-checkoutregisterpage',
  confirmPage: 'body.is-act-confirmpage',
  loginForm: 'form.login-form',
  loginToggle: '.login-collapse-toggle',
  registerForm: 'form.register-form',
  alertDanger: '.alert-danger',
  fieldInvalid: 'form.register-form .is-invalid, form.register-form .invalid-feedback:not(:empty)',
  captcha: 'form.register-form .captcha-basic, form.register-form [data-google-re-captcha-v2], form.register-form [data-google-re-captcha-v3], form.register-form iframe[src*="recaptcha"], form.register-form iframe[src*="hcaptcha"]',
  paymentMethod: '.payment-methods .payment-method-input, input.payment-method-input',
  shippingMethod: '.shipping-methods .shipping-method-input, input[name="shippingMethodId"]',
  submitOrder: '#confirmFormSubmit, button[form="confirmOrderForm"][type="submit"]',
};

const TEST_ACCOUNT = (detail) => new StepOutcome('blocked', 'test_account_rejected', detail);

const blocked = (context) => {
  if (context.forbiddenOperation) {
    throw new StepOutcome('blocked', 'forbidden_mutation', context.forbiddenOperation);
  }
};

function layoutOf(context) {
  const layout = context?.scenario?.checkout_layout;

  return layout && typeof layout === 'object' ? layout : {};
}

function onCustomConfirmPath(page, layout) {
  const path = String(layout.confirm_path ?? '').replace(/\/+$/, '');

  if (path === '') {
    return false;
  }

  try {
    return new URL(page.url()).pathname.replace(/\/+$/, '').endsWith(path);
  } catch {
    return false;
  }
}

async function pageKind(page, timeout, layout = {}) {
  if (onCustomConfirmPath(page, layout)) {
    return 'confirm';
  }

  return Promise.any([
    page.locator(SELECTORS.confirmPage).first().waitFor({ state: 'attached', timeout }).then(() => 'confirm'),
    page.locator(SELECTORS.registerPage).first().waitFor({ state: 'attached', timeout }).then(() => 'register'),
    page.locator(SELECTORS.cartPage).first().waitFor({ state: 'attached', timeout }).then(() => 'cart'),
  ]).catch(() => null);
}

async function fillFirst(form, selectors, value, timeout) {
  for (const selector of selectors) {
    const field = form.locator(selector).first();

    if ((await field.count()) > 0 && (await field.isVisible())) {
      await field.fill(value, { timeout });

      return true;
    }
  }

  return false;
}

async function selectFirstValue(form, selector, timeout, preferred = null) {
  const select = form.locator(selector).first();

  if ((await select.count()) === 0 || !(await select.isVisible())) {
    return;
  }

  const values = await select.evaluate((element) => [...element.options].map((option) => option.value));

  if (preferred !== null && values.includes(preferred)) {
    await select.selectOption(preferred, { timeout });

    return;
  }

  if ((await select.inputValue()) === '') {
    const first = values.find((value) => value !== '');

    if (first) {
      await select.selectOption(first, { timeout });
    }
  }
}

async function selectableRadio(methods, timeout) {
  for (let index = 0; index < await methods.count(); index++) {
    const method = methods.nth(index);
    if (!await method.isVisible() || !await method.isEnabled()) continue;
    if (await method.isChecked()) return true;
    await method.check({ timeout: Math.min(timeout, 5000) }).catch(() => null);
    if (await method.isChecked().catch(() => false)) return true;
  }
  return false;
}

/**
 * Shopware 6.6/6.7 storefront. Stops before «Submit order» (`POST /checkout/order` is blocked by the network policy);
 * how payment methods are reached depends on `checkout_access` (ADR 0055).
 */
export function createShopwareAdapter() {
  return {
    focus: {
      product: '.product-detail-buy, .product-detail',
      add_to_cart: '.offcanvas, .product-detail-buy',
      cart: '.checkout-main, .cart-table',
      checkout: '.checkout-main, form.register-form',
      sign_in: 'form.login-form, .checkout-main',
      guest_details: 'form.register-form',
      payment_form: '.confirm-payment-shipping, .payment-methods',
    },

    async product(page, step, context) {
      const response = await navigate(page, context.scenario.product_url, step.timeout_ms, context);

      if (response && (response.status() === 404 || response.status() === 410)) {
        throw PRODUCT(`http_${response.status()}`);
      }

      if (await visible(page, SELECTORS.buyButton, step.timeout_ms)) {
        const button = page.locator(SELECTORS.buyButton).first();

        if (await button.isDisabled()) {
          throw await present(page, SELECTORS.soldOut) ? PRODUCT('add_to_cart_disabled') : SITE('add_to_cart_disabled_without_stock_notice');
        }

        context.lineItemId = await page.locator('form.buy-widget input[name$="[id]"]').first()
          .getAttribute('name', { timeout: 2000 })
          .then((name) => /^lineItems\[([0-9a-f]{32})\]\[id\]$/.exec(name ?? '')?.[1] ?? null)
          .catch(() => null);
        context.productPath = page.url();

        return [{ code: 'add_to_cart_ready', passed: true }];
      }

      if (await present(page, SELECTORS.soldOut)) {
        throw PRODUCT('out_of_stock');
      }

      if (await present(page, SELECTORS.productPage)) {
        throw new StepOutcome('inconclusive', 'selector_changed', 'add_to_cart_not_found');
      }

      throw UNSUPPORTED(await present(page, SELECTORS.storefront) ? 'not_a_shopware_product_page' : 'not_a_shopware_storefront');
    },

    async add_to_cart(page, step, context) {
      const functionalCookies = page.locator('.cookie-consent-accept-only-functional-button:visible').first();
      if (await functionalCookies.waitFor({ state: 'visible', timeout: Math.min(step.timeout_ms, 1500) }).then(() => true).catch(() => false)) {
        await functionalCookies.click({ timeout: Math.min(step.timeout_ms, 5000) });
        await page.locator('.cookie-consent-modal:visible, .modal.show:visible').first().waitFor({ state: 'hidden', timeout: Math.min(step.timeout_ms, 5000) }).catch(() => null);
      }

      const preferredButton = page.locator('form.buy-widget button.btn-buy:not([tabindex="-1"]), .product-detail-buy button.btn-buy:not([tabindex="-1"])').first();
      const button = await preferredButton.count() > 0 ? preferredButton : page.locator(SELECTORS.buyButton).first();
      const added = page.waitForResponse((response) => response.request().method() === 'POST' && /\/checkout\/line-item\/add\/?$/.test(new URL(response.url()).pathname), { timeout: step.timeout_ms }).catch(() => null);

      await button.click({ timeout: step.timeout_ms });
      const response = await added;
      blocked(context);

      if (response === null) {
        throw SITE('add_to_cart_no_response');
      }

      if (response.status() >= 500) {
        throw SITE(`add_to_cart_http_${response.status()}`);
      }

      await page.waitForLoadState('domcontentloaded', { timeout: step.timeout_ms }).catch(() => null);

      if (await visible(page, SELECTORS.offcanvasError, 1500)) {
        throw SITE('add_to_cart_error_notice');
      }

      return [{ code: 'add_to_cart_submitted', passed: true }];
    },

    async cart(page, step, context) {
      await navigate(page, context.scenario.cart_url, step.timeout_ms, context);

      if (await visible(page, SELECTORS.cartLine, step.timeout_ms)) {
        const rows = page.locator('.line-item-product:visible, .line-item.is-product:visible');
        let expectedRow = null;
        for (let index = 0; index < await rows.count(); index++) {
          const row = rows.nth(index);
          const { action, link } = await row.evaluate((element) => ({
            action: element.querySelector('form[action*="/checkout/line-item/change-quantity/"]')?.getAttribute('action') ?? null,
            link: element.querySelector('a.line-item-label[href]')?.href ?? null,
          }));
          if ((context.lineItemId && action?.endsWith(`/${context.lineItemId}`)) || (link && sameProductPath(context.productPath, link))) {
            expectedRow = row;
            break;
          }
        }
        if (!expectedRow) throw SITE('cart_expected_product_missing');

        const lineText = await expectedRow.locator(SELECTORS.cartLineTotal).first().textContent().catch(() => null);
        const totalText = await page.locator(SELECTORS.cartTotal).first().textContent().catch(() => null);
        const lineMinor = displayedMinor(lineText);
        const totalMinor = displayedMinor(totalText);
        if (lineMinor === null || totalMinor === null) {
          throw new StepOutcome('inconclusive', 'selector_changed', 'cart_amount_not_readable');
        }
        if (lineMinor <= 0n || totalMinor <= 0n) throw SITE('cart_amount_not_positive');

        if (!await visible(page, SELECTORS.checkoutControl, Math.min(step.timeout_ms, 3000))) {
          throw SITE('cart_checkout_control_missing');
        }

        const checkout = page.locator(SELECTORS.checkoutControl).first();
        if (await checkout.isDisabled() || await checkout.getAttribute('aria-disabled') === 'true' || await checkout.evaluate((element) => getComputedStyle(element).pointerEvents === 'none')) {
          throw SITE('cart_checkout_control_disabled');
        }

        try {
          await checkout.click({ timeout: step.timeout_ms });
          await page.waitForURL((url) => url.pathname !== new URL(context.scenario.cart_url).pathname, { timeout: step.timeout_ms });
        } catch {
          throw SITE('cart_checkout_navigation_failed');
        }

        return [{ code: 'cart_expected_product', passed: true }, { code: 'cart_positive_amount', passed: true }, { code: 'checkout_control_works', passed: true }];
      }

      if (await present(page, SELECTORS.cartPage)) {
        throw SITE('cart_empty_after_add');
      }

      throw UNSUPPORTED('not_a_shopware_cart_page');
    },

    async checkout(page, step, context) {
      await navigate(page, context.scenario.checkout_url, step.timeout_ms, context);
      const kind = await pageKind(page, step.timeout_ms, layoutOf(context));

      if (kind === 'register') {
        if (!(await present(page, `${SELECTORS.loginForm}, ${SELECTORS.registerForm}`))) {
          throw new StepOutcome('inconclusive', 'selector_changed', 'sign_in_forms_not_found');
        }

        return [{ code: 'checkout_rendered', passed: true, detail_code: 'sign_in_offered' }];
      }

      if (kind === 'confirm') {
        return [{ code: 'checkout_rendered', passed: true, detail_code: 'confirm' }];
      }

      if (kind === 'cart') {
        throw SITE('checkout_redirected_to_cart');
      }

      if (await present(page, SELECTORS.alertDanger)) {
        throw SITE('checkout_error_notice');
      }

      throw UNSUPPORTED('not_a_shopware_checkout_page');
    },

    async sign_in(page, step, context) {
      const account = context.scenario.sign_in;

      if (!account?.email || !account?.password) {
        throw TEST_ACCOUNT('sign_in_not_configured');
      }

      const form = page.locator(SELECTORS.loginForm).first();

      if ((await form.count()) === 0) {
        throw new StepOutcome('inconclusive', 'selector_changed', 'login_form_not_found');
      }

      const username = form.locator('input[name="username"]').first();

      if (!(await username.isVisible())) {
        await page.locator(SELECTORS.loginToggle).first().click({ timeout: step.timeout_ms }).catch(() => null);
      }

      await username.fill(account.email, { timeout: step.timeout_ms });
      await form.locator('input[name="password"]').first().fill(account.password, { timeout: step.timeout_ms });
      const answered = page.waitForResponse((response) => response.request().method() === 'POST' && /\/account\/login\/?$/.test(new URL(response.url()).pathname), { timeout: step.timeout_ms }).catch(() => null);
      await form.locator('button[type="submit"]').first().click({ timeout: step.timeout_ms });
      const response = await answered;
      blocked(context);

      if (response !== null && response.status() >= 500) {
        throw SITE(`sign_in_http_${response.status()}`);
      }

      context.signedIn = response !== null && response.status() < 400;
      const kind = await pageKind(page, step.timeout_ms, layoutOf(context));

      if (kind === 'confirm') {
        context.signedIn = true;

        return [{ code: 'test_customer_signed_in', passed: true }];
      }

      if (await present(page, SELECTORS.alertDanger) || await present(page, SELECTORS.loginForm)) {
        context.signedIn = false;
        throw TEST_ACCOUNT('sign_in_rejected');
      }

      throw new StepOutcome('inconclusive', 'selector_changed', 'after_sign_in_unknown_page');
    },

    async guest_details(page, step, context) {
      const form = page.locator(SELECTORS.registerForm).first();

      if ((await form.count()) === 0) {
        throw new StepOutcome('inconclusive', 'selector_changed', 'register_form_not_found');
      }

      if (await present(page, SELECTORS.captcha)) {
        throw new StepOutcome('blocked', 'waf_challenge', 'captcha_on_registration');
      }

      const account = form.locator('input[name="createCustomerAccount"]').first();

      if ((await account.count()) > 0 && (await account.isChecked())) {
        await account.uncheck({ timeout: step.timeout_ms });
      }

      const timeout = step.timeout_ms;
      await selectFirstValue(form, 'select[name="accountType"]', timeout, 'private');
      await selectFirstValue(form, 'select[name="salutationId"]', timeout);
      await fillFirst(form, ['input[name="billingAddress[firstName]"]', 'input[name="firstName"]'], SYNTHETIC_GUEST.firstName, timeout);
      await fillFirst(form, ['input[name="billingAddress[lastName]"]', 'input[name="lastName"]'], SYNTHETIC_GUEST.lastName, timeout);
      await fillFirst(form, ['input[name="email"]'], SYNTHETIC_GUEST.email, timeout);
      await fillFirst(form, ['input[name="billingAddress[street]"]'], SYNTHETIC_GUEST.street, timeout);
      await fillFirst(form, ['input[name="billingAddress[zipcode]"]'], context.scenario.synthetic_location?.postcode ?? SYNTHETIC_GUEST.postcode, timeout);
      await fillFirst(form, ['input[name="billingAddress[city]"]'], SYNTHETIC_GUEST.city, timeout);
      await selectFirstValue(form, 'select[name="billingAddress[countryId]"]', timeout);

      const privacy = form.locator('input[name="acceptedDataProtection"]').first();

      if ((await privacy.count()) > 0 && (await privacy.isVisible()) && !(await privacy.isChecked())) {
        await privacy.check({ timeout });
      }

      const answered = page.waitForResponse((response) => response.request().method() === 'POST' && /\/account\/register\/?$/.test(new URL(response.url()).pathname), { timeout }).catch(() => null);
      await form.locator('button[type="submit"]').last().click({ timeout });
      const response = await answered;
      blocked(context);

      if (context.blockedUnknownMutation) {
        throw UNSUPPORTED('guest_registration_blocked');
      }

      if (response !== null && response.status() >= 500) {
        throw SITE(`guest_registration_http_${response.status()}`);
      }

      const kind = await pageKind(page, timeout, layoutOf(context));

      if (kind === 'confirm') {
        return [{ code: 'guest_registered', passed: true }];
      }

      if (await present(page, SELECTORS.fieldInvalid) || await present(page, SELECTORS.alertDanger)) {
        throw UNSUPPORTED('guest_form_rejected');
      }

      if (kind === null && await present(page, '.alert-success, .alert-info')) {
        throw UNSUPPORTED('guest_double_opt_in');
      }

      throw new StepOutcome('inconclusive', 'selector_changed', 'after_guest_registration_unknown_page');
    },

    async shipping(page, step, context) {
      if ((await pageKind(page, step.timeout_ms, layoutOf(context))) !== 'confirm') {
        throw new StepOutcome('inconclusive', 'selector_changed', 'shipping_confirm_page_not_reached');
      }

      const methods = page.locator(SELECTORS.shippingMethod);
      if (await methods.count() === 0) throw SITE('no_shipping_methods');
      if (!await methods.evaluateAll((inputs) => inputs.some((input) => input.getClientRects().length > 0))) {
        throw new StepOutcome('inconclusive', 'selector_changed', 'shipping_controls_not_visible');
      }
      const selectable = await selectableRadio(methods, step.timeout_ms);
      blocked(context);
      if (context.blockedUnknownMutation) throw UNSUPPORTED('shipping_selection_blocked');
      if (!selectable) throw SITE('shipping_methods_unselectable');

      return [{ code: 'delivery_option_selectable', passed: true }];
    },

    async payment_form(page, step, context) {
      if ((await pageKind(page, step.timeout_ms, layoutOf(context))) !== 'confirm') {
        throw new StepOutcome('inconclusive', 'selector_changed', 'confirm_page_not_reached');
      }

      const layout = layoutOf(context);
      const methods = page.locator(layout.payment_method || SELECTORS.paymentMethod);
      if (await methods.count().catch(() => 0) === 0) {
        if (layout.payment_method) throw new StepOutcome('inconclusive', 'selector_changed', 'layout_payment_method_not_found');
        throw SITE('no_payment_methods');
      }
      if (!await methods.evaluateAll((inputs) => inputs.some((input) => input.getClientRects().length > 0))) {
        throw new StepOutcome('inconclusive', 'selector_changed', 'payment_controls_not_visible');
      }
      const selectable = await selectableRadio(methods, step.timeout_ms);
      blocked(context);
      if (context.blockedUnknownMutation) throw UNSUPPORTED('payment_selection_blocked');
      if (!selectable) throw SITE('payment_methods_unselectable');

      const submit = page.locator(layout.submit_button || SELECTORS.submitOrder).first();

      if ((await submit.count().catch(() => 0)) === 0) {
        if (layout.submit_button) throw new StepOutcome('inconclusive', 'selector_changed', 'layout_submit_button_not_found');
        throw SITE('order_submit_missing');
      }

      if (await submit.isDisabled()) {
        throw SITE('order_submit_unavailable');
      }

      const assertions = [{ code: 'payment_method_selectable', passed: true, detail_code: `methods_${Math.min(await methods.count(), 99)}` }];

      if (context.scenario.expect_checkout_script) {
        const marker = await page.waitForFunction(() => window.BusinessWatchdogCheckout ?? null, null, { timeout: 5000 }).then((handle) => handle.jsonValue()).catch(() => null);
        const script = marker?.page ?? null;
        assertions.push({ code: 'checkout_script_present', passed: script === 'confirm', detail_code: script === null ? 'script_missing' : `page_${script}` });

        if (script === 'confirm' && marker.found && typeof marker.found === 'object') {
          const missing = ['form', 'submit'].filter((element) => marker.found[element] !== true).concat(Number(marker.found.payment_methods) > 0 ? [] : ['payment_methods']);
          assertions.push({ code: 'checkout_script_elements', passed: missing.length === 0, detail_code: missing.length === 0 ? `layout_${marker.layout === 'custom' ? 'custom' : 'default'}` : `missing_${missing.join('_')}` });
        }
      }

      return assertions;
    },

    /**
     * Removes the test product from the test customer's cart, which Shopware keeps between sessions; best effort,
     * also after a failed step.
     */
    async cleanup(page, context, timeout) {
      if (!context.signedIn || !context.lineItemId) {
        return null;
      }

      await navigate(page, context.scenario.cart_url, timeout, context);
      const remove = page.locator(`form[action*="/checkout/line-item/delete/${context.lineItemId}"] button[type="submit"]`).first();

      if ((await remove.count()) === 0) {
        return 'cart_clean';
      }

      const answered = page.waitForResponse((response) => response.request().method() === 'POST' && response.url().includes(`/checkout/line-item/delete/${context.lineItemId}`), { timeout }).catch(() => null);
      await remove.click({ timeout });

      return (await answered) === null ? 'cart_cleanup_no_response' : 'cart_cleaned';
    },
  };
}
