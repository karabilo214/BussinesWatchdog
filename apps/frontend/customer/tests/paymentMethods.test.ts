import { describe, expect, it } from 'vitest';
import { PAYMENT_METHOD_KINDS, paymentMethodDisplayName, paymentMethodKind, paymentMethodLabelKey } from '@bw/ui';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';

describe('payment method labels', () => {
  it('recognises WooCommerce gateway ids and Shopware technical names', () => {
    expect(paymentMethodKind('stripe_shopware_payment_payment_handler_card')).toBe('card');
    expect(paymentMethodKind('stripe')).toBe('card');
    expect(paymentMethodKind('woocommerce_payments')).toBe('card');
    expect(paymentMethodKind('stripe_sepa')).toBe('sepa_debit');
    expect(paymentMethodKind('stripe_shopware_payment_payment_handler_sepa')).toBe('sepa_debit');
    expect(paymentMethodKind('ppcp-gateway')).toBe('paypal');
    expect(paymentMethodKind('swag_paypal_paypal')).toBe('paypal');
    expect(paymentMethodKind('stripe_klarna')).toBe('klarna');
    expect(paymentMethodKind('payment_invoice')).toBe('invoice');
    expect(paymentMethodKind('payment_prepayment')).toBe('prepayment');
    expect(paymentMethodKind('payment_debitpayment')).toBe('sepa_debit');
    expect(paymentMethodKind('payment_cashpayment')).toBe('cash');
    expect(paymentMethodKind('bacs')).toBe('bank_transfer');
    expect(paymentMethodKind('cod')).toBe('cash_on_delivery');
    expect(paymentMethodKind('stripe_applepay')).toBe('apple_pay');
    expect(paymentMethodKind('my_custom_gateway')).toBeNull();
  });

  it('falls back to «other» for an unknown code and to no label without a code', () => {
    expect(paymentMethodLabelKey('my_custom_gateway')).toBe('payment_method_kind.other');
    expect(paymentMethodLabelKey('')).toBeNull();
    expect(paymentMethodLabelKey(null)).toBeNull();
  });

  it('uses the store title only for unknown codes and keeps the code separate', () => {
    const translate = (key: string) => (ru.payment_method_kind as Record<string, string>)[key.split('.')[1]!]!;
    expect(paymentMethodDisplayName('merchant_custom', 'Оплата в магазине', translate)).toBe('Оплата в магазине');
    expect(paymentMethodDisplayName('merchant_custom', 'merchant_custom', translate)).toBe(ru.payment_method_kind.other);
    expect(paymentMethodDisplayName('merchant_custom', null, translate)).toBe(ru.payment_method_kind.other);
    expect(paymentMethodDisplayName('stripe', 'Merchant card title', translate)).toBe(ru.payment_method_kind.card);
    expect(paymentMethodDisplayName(null, 'No code', translate)).toBeNull();
  });

  it('has a label for every kind in ru, en and de', () => {
    for (const locale of [ru, en, de]) {
      const labels = locale.payment_method_kind as Record<string, string>;
      expect([...PAYMENT_METHOD_KINDS, 'other'].filter((kind) => !labels[kind])).toEqual([]);
    }
  });
});
