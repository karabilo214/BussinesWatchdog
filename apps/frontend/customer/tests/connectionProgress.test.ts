import { mount } from '@vue/test-utils';
import { ApiError } from '@bw/api-client';
import { beforeEach, describe, expect, it } from 'vitest';
import ConnectionProgress from '@/components/ConnectionProgress.vue';
import { VERIFICATION_REASONS } from '@/components/verificationReasons';
import { localization, setLocale } from '@/i18n';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';

function stages(platform: 'woocommerce' | 'shopware' | 'stripe' | 'paypal', code: string): string[] {
  const wrapper = mount(ConnectionProgress, { props: { platform, error: new ApiError(422, code) }, global: { plugins: [localization] } });

  return wrapper.findAll('li').map((item) => item.text());
}

beforeEach(() => setLocale('ru'));

describe('what the key check has already established', () => {
  it('shows the passed steps for Stripe and PayPal', () => {
    expect(stages('stripe', 'stripe_refunds_read_missing')).toEqual(['✓ Данные введены в правильном формате', '✓ Stripe отвечает', '✓ Stripe принял ключ']);
    expect(stages('stripe', 'stripe_key_rejected')).toEqual(['✓ Данные введены в правильном формате', '✓ Stripe отвечает']);
    expect(stages('stripe', 'stripe_account_mismatch')).toHaveLength(4);
    expect(stages('paypal', 'paypal_transaction_search_missing')).toEqual(['✓ Данные введены в правильном формате', '✓ PayPal отвечает', '✓ PayPal принял ключ']);
    expect(stages('paypal', 'paypal_credentials_invalid')).toEqual([]);
    expect(stages('stripe', 'stripe_unreachable')).toEqual([]);
  });

  it('keeps the store platform steps', () => {
    expect(stages('woocommerce', 'woocommerce_order_read_missing')).toHaveLength(3);
    expect(stages('shopware', 'shopware_challenge')).toEqual(['✓ Сайт ответил']);
  });

  it('names the missing Stripe permission in three languages', () => {
    for (const resource of ['payment_intents', 'charges', 'refunds']) {
      for (const locale of [ru, en, de]) {
        expect((locale.errors as Record<string, string>)[`stripe_${resource}_read_missing`]).toMatch(/: Read/);
      }
    }
  });

  it('has a text for every domain verification reason in three languages', () => {
    for (const reason of VERIFICATION_REASONS) {
      for (const locale of [ru, en, de]) {
        expect((locale.store.verification.reason as Record<string, string>)[reason], reason).toBeTruthy();
      }
    }
  });
});
