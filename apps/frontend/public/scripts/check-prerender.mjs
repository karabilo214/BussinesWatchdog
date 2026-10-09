// The public site must be readable without JavaScript: every language page is prerendered with its own lang, title and text.
import { readFileSync } from 'node:fs';

const expectations = {
  ru: 'Узнайте, что оплата сломалась, раньше покупателей',
  en: 'Find out that payments broke before your customers do',
  de: 'Erfahren Sie vor Ihren Kunden, dass die Zahlung nicht funktioniert',
};
const problems = [];

for (const [locale, heading] of Object.entries(expectations)) {
  const html = readFileSync(new URL(`../dist/${locale}/index.html`, import.meta.url), 'utf8');

  if (!html.includes(`lang="${locale}"`)) problems.push(`${locale}: <html lang> is not ${locale}`);
  if (!html.includes(heading)) problems.push(`${locale}: heading is not prerendered`);
  if (!/<title>[^<]+<\/title>/.test(html)) problems.push(`${locale}: no <title>`);
  if (!html.includes('name="description"')) problems.push(`${locale}: no meta description`);
}

const chooser = readFileSync(new URL('../dist/index.html', import.meta.url), 'utf8');

for (const locale of Object.keys(expectations)) {
  if (!chooser.includes(`href="/${locale}/"`)) problems.push(`chooser: no link to /${locale}/`);
}

if (problems.length > 0) {
  console.error(problems.join('\n'));
  process.exit(1);
}

console.log('prerender ok (/, /ru/, /en/, /de/)');
