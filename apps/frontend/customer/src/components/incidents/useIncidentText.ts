import { useI18n } from 'vue-i18n';
import type { Incident } from '@/api/types';
import { formatMinor } from '@bw/ui';
import { AUTO_RESOLUTIONS } from './tones';

export function useIncidentText() {
  const { t, te, locale } = useI18n();

  function title(code: string): string {
    return te(`incident.title.${code}`) ? t(`incident.title.${code}`) : t('incident.title.unknown', { code });
  }

  /** A money incident names the store order it is about, so the list says which order to open. */
  function incidentTitle(incident: Pick<Incident, 'title_code' | 'family' | 'order_display_number'>): string {
    const base = title(incident.title_code);

    return incident.family === 'money' && incident.order_display_number ? t('incident.title_with_order', { title: base, order: incident.order_display_number }) : base;
  }

  function advice(code: string): string {
    return te(`incident.advice.${code}`) ? t(`incident.advice.${code}`) : t('incident.advice.unknown');
  }

  function amount(minor: string | null, currency: string | null, exponent: number | null): string | null {
    if (minor === null || currency === null || exponent === null) {
      return null;
    }

    return formatMinor(minor, currency, exponent, locale.value);
  }

  function discrepancy(incident: Incident): string | null {
    return amount(incident.verified_discrepancy_minor, incident.currency, incident.currency_exponent);
  }

  /** Automatic resolutions are stable codes; a manual resolution is the operator's own text. */
  function resolution(reason: string | null): string | null {
    if (reason === null) {
      return null;
    }

    return AUTO_RESOLUTIONS.includes(reason) ? t(`incident.resolution.${reason}`) : reason;
  }

  return { title, incidentTitle, advice, amount, discrepancy, resolution };
}
