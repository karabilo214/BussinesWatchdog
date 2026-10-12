import { useI18n } from 'vue-i18n';

export function useFormat() {
  const { locale, t } = useI18n();

  function dateTime(iso: string | null): string {
    if (iso === null) {
      return t('common.never');
    }

    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
  }

  /** "в месяц" for one unit, otherwise "каждые 2 недели" / "every 2 weeks" / "alle 2 Wochen". */
  function period(interval: string, count: number): string {
    if (!['day', 'week', 'month', 'year'].includes(interval)) {
      return '';
    }

    if (count === 1) {
      return t(`common.per.${interval}`);
    }

    const duration = new Intl.NumberFormat(locale.value, { style: 'unit', unit: interval, unitDisplay: 'long' }).format(count);
    const form = new Intl.PluralRules(locale.value).select(count) === 'one' ? 'every_one' : 'every';

    return t(`common.per.${form}`, { duration });
  }

  return { dateTime, period };
}
