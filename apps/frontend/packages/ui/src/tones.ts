/** Status colours from docs/design/palette.md. "unknown" is used for anything not known to be fine. */
export type Tone = 'ok' | 'warn' | 'crit' | 'note' | 'unknown';

export const TONE_CLASSES: Record<Tone, string> = {
  ok: 'bg-ok-soft text-ok border-ok-border',
  warn: 'bg-warn-soft text-warn border-warn-border',
  crit: 'bg-crit-soft text-crit border-crit-border',
  note: 'bg-note-soft text-note border-note-border',
  unknown: 'bg-unknown-soft text-unknown border-unknown-border',
};

export const SOLID_TONE_CLASSES: Record<Tone, string> = {
  ok: 'bg-ok-solid text-white border-ok-solid',
  warn: 'bg-warn-solid text-white border-warn-solid',
  crit: 'bg-crit-solid text-white border-crit-solid',
  note: 'bg-note-solid text-white border-note-solid',
  unknown: 'bg-unknown-solid text-white border-unknown-solid',
};
