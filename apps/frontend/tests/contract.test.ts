import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import en from '@/i18n/locales/en.json';
import { BROWSER_CHECK_TONES, CONNECTOR_TONES, MONEY_TONES, PAYMENT_ATTEMPTS_TONES, type Tone } from '@/components/tones';

const HEALTHY: Record<string, string[]> = {
  connector: ['fresh'],
  money: ['reconciling'],
  payment_attempts: ['observing'],
  browser_checks: ['passing'],
};

/** Enum values of StoreCoverage.<part>.state in contracts/openapi.yaml. */
function contractStates(part: string): string[] {
  const yaml = readFileSync(resolve(__dirname, '../../../contracts/openapi.yaml'), 'utf8');
  const coverage = yaml.slice(yaml.indexOf('\n    StoreCoverage:'), yaml.indexOf('\n    Store:'));
  const section = coverage.slice(coverage.indexOf(`\n        ${part}:`));
  const enumAt = section.indexOf('enum:');
  const rest = section.slice(enumAt + 'enum:'.length);
  const inline = /^\s*\[([^\]]+)\]/.exec(rest);

  if (inline) {
    return inline[1]!.split(',').map((value) => value.trim());
  }

  const items: string[] = [];

  for (const line of rest.split('\n').slice(1)) {
    const item = /^\s+- ([a-z_]+)$/.exec(line);

    if (item === null) {
      break;
    }

    items.push(item[1]!);
  }

  return items;
}

const TONES: Record<string, Record<string, Tone>> = {
  connector: CONNECTOR_TONES,
  money: MONEY_TONES,
  payment_attempts: PAYMENT_ATTEMPTS_TONES,
  browser_checks: BROWSER_CHECK_TONES,
};

describe('store coverage states', () => {
  for (const part of Object.keys(TONES)) {
    it(`${part}: every contract state has a colour and a label, and only healthy states are green`, () => {
      const states = contractStates(part);
      const labels = (en.coverage as Record<string, Record<string, string>>)[part]!;

      expect(new Set(states)).toEqual(new Set(Object.keys(TONES[part]!)));

      for (const state of states) {
        expect(labels[state], `${part}.${state} label`).toBeTruthy();
        expect(TONES[part]![state] === 'ok', `${part}.${state} green`).toBe(HEALTHY[part]!.includes(state));
      }
    });
  }
});
