// The public site is rendered by Laravel (resources/views/site); this package only builds its stylesheet into
// apps/backend/public/site with a manifest that App\Support\Site\SiteAssets reads.
import { readFileSync } from 'node:fs';

const root = new URL('../../../backend/public/site/', import.meta.url);
const manifest = JSON.parse(readFileSync(new URL('.vite/manifest.json', root), 'utf8'));
const entry = manifest['src/site.css'];
const problems = [];

if (entry?.file === undefined) {
  problems.push('manifest has no src/site.css entry');
} else {
  const css = readFileSync(new URL(entry.file, root), 'utf8');

  for (const selector of ['.site-header', '.site-plan', '.site-form', '.site-content .grid-2', '.site-content .button', '--color-primary']) {
    if (!css.includes(selector)) problems.push(`stylesheet has no ${selector}`);
  }
}

if (problems.length > 0) {
  console.error(problems.join('\n'));
  process.exit(1);
}

console.log(`site stylesheet ok (${entry.file})`);
