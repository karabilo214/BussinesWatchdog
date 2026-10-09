import { useI18n } from 'vue-i18n';

export function useFormat() {
  const { locale, t } = useI18n();

  function dateTime(iso: string | null): string {
    if (iso === null) {
      return t('common.never');
    }

    return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(iso));
  }

  return { dateTime };
}
