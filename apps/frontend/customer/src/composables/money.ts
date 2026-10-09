/**
 * Formats a minor-unit decimal string (e.g. "18400", exponent 2 → "184,00 €") without ever converting it to a JS
 * Number: Intl.NumberFormat formats decimal strings exactly.
 */
export function formatMinor(minor: string, currency: string, exponent: number, locale: string): string | null {
  if (!/^-?\d+$/.test(minor) || !Number.isInteger(exponent) || exponent < 0 || exponent > 6 || !/^[A-Z]{3}$/.test(currency)) {
    return null;
  }

  const negative = minor.startsWith('-');
  const digits = (negative ? minor.slice(1) : minor).padStart(exponent + 1, '0');
  const whole = exponent === 0 ? digits : digits.slice(0, -exponent);
  const fraction = exponent === 0 ? '' : digits.slice(-exponent);
  const decimal = `${negative ? '-' : ''}${whole}${fraction === '' ? '' : `.${fraction}`}`;

  try {
    const formatter = new Intl.NumberFormat(locale, {
      style: 'currency',
      currency,
      minimumFractionDigits: exponent,
      maximumFractionDigits: exponent,
    });

    return (formatter.format as unknown as (value: string) => string)(decimal);
  } catch {
    return `${decimal} ${currency}`;
  }
}
