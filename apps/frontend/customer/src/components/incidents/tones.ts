import type { Tone } from '@bw/ui';
import type { IncidentSeverity, IncidentState } from '@/api/types';

export const SEVERITY_TONES: Record<IncidentSeverity, Tone> = { critical: 'crit', warning: 'warn', info: 'note' };

/** Only a resolved incident is green; acknowledged means seen, not fixed. */
export const STATE_TONES: Record<IncidentState, Tone> = { open: 'crit', acknowledged: 'warn', resolved: 'ok' };

export const AUTO_RESOLUTIONS = [
  'auto_resolved_fresh_reconciliation_ok',
  'auto_resolved_successful_payment',
  'auto_resolved_scheduled_checks_passed',
  'auto_resolved_check_passed',
  'auto_resolved_connector_fresh',
];
