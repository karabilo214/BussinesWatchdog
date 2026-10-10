import type { Tone } from '@bw/ui';
import type { CheckAttempt, CheckRunStatus, CheckStep } from '@/api/types';

/** Only "passed" is green; blocked/unsupported/inconclusive say nothing about the store being fine. */
export const RUN_TONES: Record<CheckRunStatus, Tone> = {
  passed: 'ok',
  failed: 'crit',
  queued: 'note',
  running: 'note',
  inconclusive: 'unknown',
  blocked: 'unknown',
  unsupported: 'unknown',
  cancelled: 'unknown',
};

export const ATTEMPT_TONES: Record<CheckAttempt['status'], Tone> = {
  passed: 'ok',
  failed: 'crit',
  running: 'note',
  inconclusive: 'unknown',
  blocked: 'unknown',
  unsupported: 'unknown',
  cancelled: 'unknown',
  expired: 'unknown',
};

export const STEP_TONES: Record<CheckStep['status'], Tone> = { passed: 'ok', failed: 'crit', skipped: 'unknown', inconclusive: 'unknown', blocked: 'unknown' };

export const ACTIVE_RUN_STATUSES: CheckRunStatus[] = ['queued', 'running'];

export const INTERVALS = [300, 900, 1800, 3600, 21600, 86400];
