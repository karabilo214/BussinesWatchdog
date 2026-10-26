import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import de from '@/i18n/locales/de.json';
import en from '@/i18n/locales/en.json';
import ru from '@/i18n/locales/ru.json';

const TARGETS = ['store', 'order', 'check', 'artifact', 'incident'];
const EVENTS = ['support_grant.requested', 'support_grant.approved', 'support_grant.denied', 'support_grant.revoked', 'support_access.opened', 'support_access.artifact', 'store.support_renamed'];

describe('support access history', () => {
  it('names every event and every opened object in ru, en and de', () => {
    for (const locale of [ru, en, de]) {
      const texts = locale.support_grants as { event: Record<string, string>; target: Record<string, string> };
      expect(EVENTS.filter((code) => !texts.event[code])).toEqual([]);
      expect(TARGETS.filter((type) => !texts.target[type])).toEqual([]);
    }
  });

  it('shows the opened object by name, not its internal id', () => {
    const view = readFileSync(resolve(__dirname, '../src/views/SupportGrantsView.vue'), 'utf8');
    expect(view).toContain('support_grants.target.');
    expect(view).not.toContain('entry.target_id');
    expect(view).toContain('entry.before_name');
    expect(view).toContain('entry.after_name');
  });

  it('asks for a support employee by name from the list, never for an e-mail', () => {
    const view = readFileSync(resolve(__dirname, '../src/views/SupportGrantsView.vue'), 'utf8');
    expect(view).toContain('listSupportStaff()');
    expect(view).toContain('v-model="form.staff_id"');
    expect(view).not.toMatch(/staff_email|type="email"|staff\?\.email/);
    for (const locale of [ru, en, de]) {
      const texts = locale.support_grants as Record<string, unknown>;
      expect(texts.staff).toBeTruthy();
      expect(texts.staff_choose).toBeTruthy();
    }
  });
});
