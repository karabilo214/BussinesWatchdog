// Fails when the corporate palette changes without a recorded owner decision (ADR 0015).
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const FROZEN_FINGERPRINT = '807832ec28ec5aa3fcbb837dd637d8e80a8ad0d100f04115e19ffd9e5d3de5c4';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const palette = JSON.parse(readFileSync(resolve(root, 'design/palette.json'), 'utf8'));
const css = readFileSync(resolve(root, 'src/styles/palette.css'), 'utf8');
const problems = [];

const sortKeys = (value) => (value && typeof value === 'object' && !Array.isArray(value)
  ? Object.fromEntries(Object.keys(value).sort().map((key) => [key, sortKeys(value[key])]))
  : value);
const fingerprint = createHash('sha256').update(JSON.stringify(sortKeys(palette.scales))).digest('hex');

if (fingerprint !== FROZEN_FINGERPRINT) {
  problems.push(`design/palette.json changed (fingerprint ${fingerprint}); a palette change needs an owner decision and a new ADR`);
}

for (const [name, steps] of Object.entries(palette.scales)) {
  for (const [step, hex] of Object.entries(steps)) {
    if (!css.includes(`--color-${name}-${step}: ${hex};`)) {
      problems.push(`src/styles/palette.css does not define --color-${name}-${step}: ${hex}`);
    }
  }
}

const declared = [...css.matchAll(/--color-([a-z]+)-(\d{2,3}):\s*(#[0-9a-f]{6});/g)];

for (const [, name, step] of declared) {
  if (palette.scales[name]?.[step] === undefined) {
    problems.push(`src/styles/palette.css defines --color-${name}-${step}, which is not in the frozen palette`);
  }
}

if (!css.includes('--color-*: initial;')) {
  problems.push('Tailwind default colours must stay disabled (--color-*: initial)');
}

if (problems.length > 0) {
  console.error(problems.join('\n'));
  process.exit(1);
}

console.log(`palette ok (${declared.length} colours, fingerprint ${fingerprint.slice(0, 12)})`);
