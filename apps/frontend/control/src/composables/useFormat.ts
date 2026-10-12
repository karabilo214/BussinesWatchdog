import { useI18n } from 'vue-i18n';
import { formatMinor } from '@bw/ui';
import type { Localized, PlanPrice } from '@/api';

export function useFormat() {
  const { locale, t } = useI18n();

  function dateTime(iso: string | null): string {
    return iso === null ? '—' : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
  }

  function period(interval: string, count: number): string {
    if (count === 1) {
      return t(`period.per.${interval}`);
    }

    const duration = new Intl.NumberFormat(locale.value, { style: 'unit', unit: interval, unitDisplay: 'long' }).format(count);

    return t(new Intl.PluralRules(locale.value).select(count) === 'one' ? 'period.every_one' : 'period.every', { duration });
  }

  function price(item: PlanPrice): string {
    return `${formatMinor(item.amount_minor, item.currency, item.currency_exponent, locale.value) ?? `${item.amount_minor} ${item.currency}`} ${period(item.interval, item.interval_count)}`;
  }

  function localized(texts: Localized, fallback: string): string {
    return texts[locale.value as keyof Localized] ?? texts.en ?? Object.values(texts)[0] ?? fallback;
  }

  return { dateTime, period, price, localized };
}
