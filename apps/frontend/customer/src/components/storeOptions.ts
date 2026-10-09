export const CURRENCIES = ['EUR', 'USD', 'GBP', 'CHF', 'PLN', 'CZK', 'SEK', 'DKK', 'NOK', 'HUF', 'RON', 'UAH'];

export function browserTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  } catch {
    return 'UTC';
  }
}

/** IANA zones the browser knows, always including the given ones (e.g. the store's saved zone). */
export function timezones(...include: string[]): string[] {
  let zones: string[] = [];

  try {
    zones = Intl.supportedValuesOf('timeZone');
  } catch {
    zones = [];
  }

  return [...new Set([...zones, 'UTC', ...include.filter((zone) => zone !== '')])].sort();
}

export function currencies(...include: string[]): string[] {
  return [...new Set([...CURRENCIES, ...include.filter((code) => /^[A-Z]{3}$/.test(code))])];
}
