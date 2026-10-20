// A real shopper for the test stores (docs/ops/test-shops.md): buys one product on woo-test with the WooCommerce Blocks
// checkout and pays by bank transfer or with a Stripe test card. Only test-mode cards are used; prints one JSON line.
// Usage (inside the browser-worker image): BW_SHOP=https://woo-test… BW_PAY=stripe|stripe_decline|bacs node shopper.mjs
import { chromium } from 'playwright';

const shop = process.env.BW_SHOP;
const pay = process.env.BW_PAY ?? 'stripe';
const product = process.env.BW_PRODUCT ?? '/product/testkaffee-250-g/';
const cards = { stripe: '4242424242424242', stripe_decline: '4000000000000002' };

if (!/^https:\/\/woo-test\./.test(shop ?? '') || !['stripe', 'stripe_decline', 'bacs'].includes(pay)) {
  throw new Error('shopper_not_configured');
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ locale: 'de-DE' });
const result = { pay, order_received: false, order: null, error: null };

try {
  await page.goto(shop + product, { waitUntil: 'load', timeout: 45000 });
  await page.locator('form.cart .single_add_to_cart_button').first().click();
  await page.waitForTimeout(3000);
  await page.goto(`${shop}/checkout/`, { waitUntil: 'load', timeout: 45000 });

  await page.locator('#email').fill(`shopper+${Date.now()}@example.com`);
  const prefix = await page.locator('#shipping-first_name').count() > 0 ? 'shipping' : 'billing';
  await page.locator(`#${prefix}-first_name`).fill('Test');
  await page.locator(`#${prefix}-last_name`).fill('Käufer');
  await page.locator(`#${prefix}-address_1`).fill('Teststraße 5');
  await page.locator(`#${prefix}-postcode`).fill('10115');
  await page.locator(`#${prefix}-city`).fill('Berlin');
  await page.waitForTimeout(2500);

  if (pay === 'bacs') {
    await page.locator('label[for="radio-control-wc-payment-method-options-bacs"]').click();
  } else {
    await page.locator('label[for="radio-control-wc-payment-method-options-stripe"]').click();
    let card = null;

    for (let i = 0; i < 30 && card === null; i++) {
      for (const frame of page.frames()) {
        if (frame.url().startsWith('https://js.stripe.com/') && await frame.locator('input[name="number"]').count().catch(() => 0) > 0) {
          card = frame;
          break;
        }
      }

      if (card === null) {
        await page.waitForTimeout(1000);
      }
    }

    if (card === null) {
      throw new Error('stripe_card_fields_not_found');
    }

    await card.locator('input[name="number"]').fill(cards[pay]);
    await card.locator('input[name="expiry"]').fill('12 / 34');
    await card.locator('input[name="cvc"]').fill('123');
    await page.waitForTimeout(1000);
  }

  await page.locator('.wc-block-components-checkout-place-order-button').click();
  await page.waitForURL(/order-received/, { timeout: 60000 }).catch(() => null);
  result.order_received = /order-received/.test(page.url());
  result.order = (page.url().match(/order-received\/(\d+)/) ?? [])[1] ?? null;

  if (!result.order_received) {
    result.error = (await page.locator('.wc-block-components-notice-banner, .woocommerce-error').allInnerTexts().catch(() => [])).join(' | ').slice(0, 300) || 'no_order_received';
  }
} catch (error) {
  result.error = String(error?.message ?? error).split('\n')[0].slice(0, 300);
}

console.log(JSON.stringify(result));
await browser.close();
