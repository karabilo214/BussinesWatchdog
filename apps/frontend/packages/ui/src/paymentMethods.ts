export const PAYMENT_METHOD_KINDS = [
  'apple_pay', 'google_pay', 'sepa_debit', 'klarna', 'giropay', 'sofort', 'ideal', 'bancontact', 'eps', 'paypal',
  'invoice', 'prepayment', 'bank_transfer', 'cash_on_delivery', 'cash', 'cheque', 'card',
] as const;

export type PaymentMethodKind = (typeof PAYMENT_METHOD_KINDS)[number];

/** Ordered: the first matching rule wins, so wallets and local methods come before the generic card and PayPal rules. */
const RULES: ReadonlyArray<[PaymentMethodKind, RegExp]> = [
  ['apple_pay', /apple_?pay/],
  ['google_pay', /google_?pay/],
  ['sepa_debit', /sepa|debit_?payment|lastschrift|direct_?debit/],
  ['klarna', /klarna/],
  ['giropay', /giropay/],
  ['sofort', /sofort/],
  ['ideal', /(^|_)ideal($|_)/],
  ['bancontact', /bancontact/],
  ['eps', /(^|_)eps($|_)/],
  ['paypal', /paypal|ppcp/],
  ['invoice', /invoice|rechnung|purchase_?on_?account/],
  ['prepayment', /prepayment|prepaid|vorkasse/],
  ['bank_transfer', /bacs|bank_?transfer|ueberweisung|wire/],
  ['cash_on_delivery', /(^|_)cod($|_)|cash_?on_?delivery|nachnahme/],
  ['cash', /cash_?payment|(^|_)cash($|_)|barzahlung/],
  ['cheque', /cheque|check_?payment/],
  ['card', /card|credit|stripe(_cc)?$|woocommerce_payments|(^|_)cc($|_)/],
];

/**
 * The kind of a store-reported payment method code (a WooCommerce gateway id or a Shopware technical name), for a
 * readable label; null when the code is not recognised and the label must fall back to a generic «other method».
 */
export function paymentMethodKind(code: string | null | undefined): PaymentMethodKind | null {
  const normalized = (code ?? '').trim().toLowerCase().replace(/[-.\s]+/g, '_');

  if (normalized === '') {
    return null;
  }

  return RULES.find(([, pattern]) => pattern.test(normalized))?.[0] ?? null;
}

/** Translation key under `payment_method_kind` for a store-reported payment method code, or null for no code. */
export function paymentMethodLabelKey(code: string | null | undefined): string | null {
  if (code === null || code === undefined || code.trim() === '') {
    return null;
  }

  return `payment_method_kind.${paymentMethodKind(code) ?? 'other'}`;
}

/** Known codes use the panel locale; custom codes use the store's display name when available. */
export function paymentMethodDisplayName(code: string | null | undefined, storeName: string | null | undefined, translate: (key: string) => string): string | null {
  const key = paymentMethodLabelKey(code);
  if (key === null) return null;
  if (paymentMethodKind(code) !== null) return translate(key);

  const name = storeName?.trim();
  return name && name.toLowerCase() !== code?.trim().toLowerCase() ? name : translate(key);
}
