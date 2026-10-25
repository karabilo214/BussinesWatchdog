import { describe, expect, it } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';

function phpFiles(directory: string): string[] {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name);
    return entry.isDirectory() ? phpFiles(path) : entry.name.endsWith('.php') ? [path] : [];
  });
}

function backendActions(): string[] {
  const root = join(process.cwd(), '../../backend/app');
  const found = new Set<string>();
  for (const path of phpFiles(root)) {
    const php = readFileSync(path, 'utf8');
    if (!php.includes('PlatformAudit') || !php.includes('->record(')) continue;
    for (const match of php.matchAll(/'((?:billing|discount|plan|plan_price|plan_version|staff|support_grant|support_access|tenant|site|audit|settings|platform|reminder|service)\.[a-z_]+(?:\.[a-z_]+)*)'/g)) {
      const code = match[1];
      if (code && !code.startsWith('site.contact.mail.') && !['site.contact.reply.letter', 'site.contact_notify_failed', 'site.contact_reply_failed'].includes(code)) found.add(code);
    }
  }
  const tenantActions = readFileSync(join(root, 'Support/Platform/TenantActions.php'), 'utf8');
  const memberNames = tenantActions.match(/public const ACTIONS = \[([^\]]+)\]/)?.[1]?.match(/self::[A-Z_]+/g) ?? [];
  for (const memberName of memberNames) {
    const value = tenantActions.match(new RegExp(`public const ${memberName.slice(6)} = '([a-z_]+)'`))?.[1];
    if (value) found.add(`tenant.${value}`);
  }
  return [...found];
}

function keys(node: unknown, prefix = ''): string[] {
  return Object.entries(node as Record<string, unknown>).flatMap(([key, value]) => (typeof value === 'object' && value !== null ? keys(value, `${prefix}${key}.`) : [`${prefix}${key}`]));
}

describe('audit action names', () => {
  it('are translated in every language for the actions the backend records', () => {
    for (const locale of [ru, en, de]) {
      const known = keys(locale.audit_action);
      expect(backendActions().filter((code) => !known.includes(code))).toEqual([]);
    }
  });

  it('have the same codes in ru, en and de', () => {
    expect(keys(en.audit_action).sort()).toEqual(keys(ru.audit_action).sort());
    expect(keys(de.audit_action).sort()).toEqual(keys(ru.audit_action).sort());
  });

  it('keeps the CSV translations in sync with the panel', () => {
    for (const [language, locale] of [['ru', ru], ['en', en], ['de', de]] as const) {
      const exported = JSON.parse(readFileSync(join(process.cwd(), '../../backend/lang/audit', `${language}.json`), 'utf8'));
      expect(exported).toEqual(locale.audit_action);
      const values = JSON.parse(readFileSync(join(process.cwd(), '../../backend/lang/audit_values', `${language}.json`), 'utf8'));
      expect(values).toEqual({ role: locale.role, capability: locale.capability, feature: locale.feature, limit: locale.limit });
    }
  });
});
